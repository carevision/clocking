<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use maliklibs\Zkteco\Lib\ZKTeco;
use Mockery\Exception;

class Settings extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'settings';

    /**
     * @var string[]
     */
    protected $fillable = [
        'device_ip',
        'api_url',
        'company_id',
        'device_model',
        'serial_number'
    ];

    /**
     * @param $ip
     * @return string
     */
    public static function verifyStatus($ip): string
    {
        try {

            $zk = new ZKTeco($ip, 4370, 5);

            if($zk->connect()){
                return "Connected";
            }

            return "Disconnected";
        }catch (Exception $exception){
            return "Disconnected";
        }
    }

    /**
     * @param  string  $serialNumber
     * @return string
     */
    public static function getCleanSerialNumber($serialNumber): string
    {
        return preg_replace(
            '/[[:cntrl:]]/',
            '',
            collect(explode("=", $serialNumber))->last()
        );
    }
}
