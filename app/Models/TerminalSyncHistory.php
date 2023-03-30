<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminalSyncHistory extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'terminal_sync_history';

    /**
     * @var string[]
     */
    protected $fillable = [
        'uid',
        'terminal_id',
        'state',
        'timestamp',
        'type',
        'serial_number'
    ];

}
