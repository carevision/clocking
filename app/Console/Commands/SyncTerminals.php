<?php

namespace App\Console\Commands;

use App\Models\ClockingRecord;
use App\Models\Settings;
use App\Models\TerminalSyncHistory;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use League\Flysystem\Config;
use maliklibs\Zkteco\Lib\ZKTeco;
use Mockery\Exception;
use DB;

class SyncTerminals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:terminals {--cleanup : cleans the entries after saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will connect the terminals & sync their data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->clearDockerLogs();

        $cleanup = false;
        if ($this->option('cleanup')) {
            $cleanup = true;
            $this->info('Continue with clean up flag option enabled...');
        }

        set_time_limit(0);
        ini_set("memory_limit", -1);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);

        $terminals = Settings::all();

        DB::beginTransaction();

        try {

            foreach ($terminals as $terminal)
            {

                $deviceIp = $terminal->device_ip;
                $companyId = $terminal->company_id;
                $apiUrl = $terminal->api_url;

                $this->info("device ip : {$deviceIp}");

                $serialNumber = null;
                $zk = new ZKTeco($deviceIp, 4370, 20);

                try {

                    if($zk->connect()){
                        $zk->disableDevice();
                        $serialNumber = stripslashes($zk->serialNumber());
                        $serialNumber = Settings::getCleanSerialNumber($serialNumber);
                        $zk->enableDevice();
                    }

                }catch (Exception $exception){
                    $this->info($exception->getMessage());
                    $errors[] = $exception->getMessage();
                    app('sentry')->captureException($exception);
                }

                if (empty($serialNumber)){
                    $errors[] = "unable to connect to machine on this IP: ".$deviceIp.", company id:".$companyId;
                    $this->info("Machine must have a serial number fetched.");
                    $this->reportToServerOnFailure($deviceIp, $companyId, $errors);
                    break;
                }

                if (!empty($errors)){
                    $this->reportToServerOnFailure($deviceIp, $companyId, $errors);
                    continue;
                }

                /*
                 * Clocking Machine types
                    clock in 	= 0
                    clock out 	= 1
                    break out 	= 2
                    break in 	= 3
                 */

                $users = $zk->getUser();
                $usersCollection = collect($users);

                $attendances = $zk->getAttendance();

                $lastSavedTerminalHistory = TerminalSyncHistory::query()
                    ->where('serial_number', $serialNumber)
                    ->orderBy('id', 'desc')
                    ->first();

                $lastHistoryTerminalFound = 0;

                if (!empty($lastSavedTerminalHistory)){
                    $lastHistoryTerminalFound = 1;

                    $last_uid = $lastSavedTerminalHistory->uid;
                    $last_terminal_id = $lastSavedTerminalHistory->terminal_id;
                    $last_state = $lastSavedTerminalHistory->state;
                    $last_timestamp = $lastSavedTerminalHistory->timestamp;
                    $last_type = $lastSavedTerminalHistory->type;
                    $last_serial_number = $lastSavedTerminalHistory->serial_number;
                }


                $lastEntry = [];
                foreach ($attendances as $attendance){
                    /**
                     * Iterate and reach to the valid entry which needs to be created
                     *
                     */
                    if ($lastHistoryTerminalFound === 1){
                        if (($last_serial_number == $serialNumber) && (strtotime($last_timestamp) >= strtotime($attendance['timestamp']))){
//                            $this->info("skipping entry..");
                            continue;
                        }
                    }

//                    $this->info("found new entry..");

                    $clockIn = "";
                    $clockOut = "";
                    $breakIn = "";
                    $breakOut = "";

                    $type = $attendance['type'];

                    if ($type == 1){
                        $clockOut = $attendance['timestamp'];
                    }

                    if ($type == 2) {
                        $breakOut = $attendance['timestamp'];
                    }

                    if ($type == 3) {
                        $breakIn = $attendance['timestamp'];
                    }

                    if ($type == 0){
                        $clockIn = $attendance['timestamp'];
                    }


                    //$cleanId = hexdec($attendance->get('id'));
                    //$attendanceId = (int) ltrim((string) $cleanId, '0');

                    $storeAttendance = [
                        "UID" => $attendance['id'],
                        "name" => $users[$attendance['id']] ?? $attendance['id'],
                        "clocking_in" => $clockIn,
                        "clocking_out" => $clockOut,
                        "break_in" => $breakIn,
                        "break_out" => $breakOut,
                        "status" => $type,
                        "company_id" => $companyId,
                        "serial_number" => $serialNumber,
                        'raw_data' => json_encode($attendance)
                    ];

                    /**
                     * If cursor reached here that mean it needs to be created and not skipped
                     */

                    ClockingRecord::query()->create($storeAttendance);
                    $lastEntry = $attendance;
                    //$lastEntry->id = $cleanId;
                }

                if (!empty($storeAttendance)){
                    $storeSyncTerminal = [
                        'uid' => $lastEntry['id'],
                        'terminal_id' => $lastEntry['uid'],
                        'state' => $lastEntry['state'],
                        'timestamp' => $lastEntry['timestamp'],
                        'type' => $lastEntry['type'],
                        'serial_number' => $serialNumber
                    ];

                    TerminalSyncHistory::query()->create($storeSyncTerminal);
                }

                /**
                 * If clean up flag is enabled then it will run the cron with force clean up options
                 */
                if ($cleanup){
                    $zk->clearAttendance();
                }

                $zk->enableDevice();
            }

            /**
             * Sensitive Command By using database transactions we make sure if everything was committed successfully
             * We can now safely clear this terminal's entries because we have written this in our local database
             *
             * Which additionally gets backed up to the Cloud Services Periodically
             */

            DB::commit();
            // all good
        } catch (\Exception $e) {
            DB::rollback();
            // something went wrong
            app('sentry')->captureException($e);
        }

        return 0;
    }

    private function reportToServerOnFailure($ip='', $company_id='', $errors=[]) {
        /*$client = new Client();
        $endPointUrl = config('server.url');
        $response = $client->request('POST', $endPointUrl.'clocking/error/log', [
            'form_params' => [
                'ip' => $ip,
                'company_id' => $company_id,
                'error_message' => implode(",", $errors)
            ]
        ]);*/

        app('sentry')->captureMessage(implode(",", $errors));

        /*if ($response->getStatusCode() === 200) {
            $responseCollection = collect(json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR));

            if (!empty($responseCollection->get('status')) && $responseCollection->get('status') == "success") {
                DB::table('error_logs')->insert([
                    "ip" => $ip,
                    "error" => implode(",", $errors)
                ]);
            }
        }*/
    }

    public function clearDockerLogs(){
        $logFiles = glob('/var/www/html/storage/logs/*.log');

        foreach ($logFiles as $logFile) {
            if (is_file($logFile)) {
                // Delete the log file
                unlink($logFile);
            }
        }

//        // Define the command to execute
//        $command = 'docker system prune -af';
//
//        // Execute the command on the Docker host machine
//        exec('docker exec -i $(docker ps -q) sh -c \''.$command.'\'', $output, $exitCode);
//
//        // Check the exit code and print the output
//        if ($exitCode === 0) {
//            echo "Command succeeded!\n";
//            echo implode("\n", $output);
//        } else {
//            echo "Command failed!\n";
//            echo implode("\n", $output);
//        }

//        $output = '';
//        $exitCode = '';
//        // Check the underlying operating system
//        if (PHP_OS === 'WINNT') {
//            // This is a Windows system, run Windows command
//            exec('cmd.exe /C docker system prune -af', $output, $exitCode);
//        } elseif (PHP_OS === 'Darwin') {
//            // This is a macOS system, run macOS command
//            exec('docker system prune -af', $output, $exitCode);
//        } else {
//            // This is a Linux system, run Linux command
//            exec('docker system prune -af', $output, $exitCode);
//        }
//
//        // Check the exit code and print the output
//        if ($exitCode === 0) {
//            $this->info("Command succeeded!\n");
//            $this->info(implode("\n", $output));
//        } else {
//            $this->info("Command failed!\n");
//            $this->info(implode("\n", $output));
//        }
//
//
//        $output = '';
//        $exitCode = '';
//        // Check the underlying operating system
//        if (PHP_OS === 'WINNT') {
//            // This is a Windows system, run Windows command
//            exec('cmd.exe /C docker image prune -af', $output, $exitCode);
//        } elseif (PHP_OS === 'Darwin') {
//            // This is a macOS system, run macOS command
//            exec('docker image prune -af', $output, $exitCode);
//        } else {
//            // This is a Linux system, run Linux command
//            exec('docker image prune -af', $output, $exitCode);
//        }
//
//        // Check the exit code and print the output
//        if ($exitCode === 0) {
//            $this->info("Command succeeded!\n");
//            $this->info(implode("\n", $output));
//        } else {
//            $this->info("Command failed!\n");
//            $this->info(implode("\n", $output));
//        }
//
//        $output = '';
//        $exitCode = '';
//        // Check the underlying operating system
//        if (PHP_OS === 'WINNT') {
//            // This is a Windows system, run Windows command
//            exec('cmd.exe /C docker container prune -f', $output, $exitCode);
//        } elseif (PHP_OS === 'Darwin') {
//            // This is a macOS system, run macOS command
//            exec('docker container prune -f', $output, $exitCode);
//        } else {
//            // This is a Linux system, run Linux command
//            exec('docker container prune -f', $output, $exitCode);
//        }
//
//        // Check the exit code and print the output
//        if ($exitCode === 0) {
//            $this->info("Command succeeded!\n");
//            $this->info(implode("\n", $output));
//        } else {
//            $this->info("Command failed!\n");
//            $this->info(implode("\n", $output));
//        }
    }
}
