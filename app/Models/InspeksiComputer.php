<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspeksiComputer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'inv_computer_id',
        'pica_number',
        'created_date',
        'month',
        'year',
        'inspection_at',
        'inspection_status',
        'inspection_image',
        'triwulan',
        'inspector',
        'conditions',
        'physique_condition_cpu',
        'physique_condition_internal_cpu',
        'physique_condition_monitor',
        'software_license',
        'software_standaritation',
        'software_device_name_standaritation',
        'software_windows_update',
        'software_storage_health',
        'software_clear_cache',
        'software_system_restore',
        'defrag',
        'security_change_password',
        'security_auto_lock',
        'security_input_password',
        'crew',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'remarks',
        'due_date',
        'inventory_status',
        'ip_address',
        'location',
        'approved_by',
        'status_approval',
        'site',
        'updated_at',
        'last_edited_by'
    ];

    
    public function computer()
    {
        return $this->belongsTo(InvComputer::class, 'inv_computer_id', 'id');
    }
    
}
