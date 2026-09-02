<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspeksiServer extends Model
{
    use HasUuids, SoftDeletes;


    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'inv_server_id',
        'pica_number',
        'created_date',
        'inspection_at',
        'condition',
        'month',
        'year',
        'inspection_status',
        'last_edited_by',
        'inspector',
        'physical_condition',
        'power_network_cable',
        'asset_label',
        'room_temperature',
        'room_humidity',
        'room_cleanliness',
        'ups_status',
        'cpu_memory_fan',
        'power_supply',
        'hypervisor_health',
        'operating_system',
        'security_patch',
        'firewall_status',
        'antivirus',
        'system_log',
        'time_synchronization',
        'storage_capacity',
        'backup_restore_review',
        'asset_registered',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'remarks',
        'due_date',
        'inventory_status',
        'approved_by',
        'status_approval',
        'inspection_image',
        'site',
    ];

    public function Server()
    {
        return $this->belongsTo(
            InvServer::class,
            'inv_server_id',
            'id'
        );
    }
}
