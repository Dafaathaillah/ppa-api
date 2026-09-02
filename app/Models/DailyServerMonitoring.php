<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyServerMonitoring extends Model
{
    use HasUuids, SoftDeletes;
    public $incrementing = false;

    protected $keyType = 'string';
    protected $fillable = [
        'month',
        'year',
        'inspection_at',
        'inspection_status',
        'last_edited_by',
        'inspector',

        'server_hypervisor_avability',
        'hardware_alert_system',
        'cpu_memory',
        'storage_capacity',
        'backup',
        'critical_system_log',
        'antivirus',
        'time_synchronization',
        'temp_humidity_room',
        'server_room_condition',
        'ups',
        'room_condition',

        'findings',
        'findings_image',
        'first_action',
        'findings_action',
        'action_image',
        'findings_status',
        'remarks',
        'inspection_image',

        'checked_by',
        'checked_at',
        'verifie_by',
        'verifie_at',
        'status_approval',
        'site',
    ];
    public function user()
    {
        return $this->belongsTo(
            User::class,
            'last_edited_by',
            'nrp'
        );
    }
}
