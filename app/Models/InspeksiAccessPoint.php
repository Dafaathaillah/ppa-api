<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspeksiAccessPoint extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'inspeksi_access_points';

    protected $fillable = [
        'inv_ap_id',
        'site',
        'ip_address',
        'month',
        'year',
        'inspection_status',
        'inspection_at',
        'inspection_by',
        'condition',
        'device_status',
        'remarks',
        'has_findings',
        'findings',
        'findings_image',
        'corrective_action',
        'corrective_action_image',
        'finding_status',
        'target_completion_date',
        'pica_number',
        'inventory_status',
        'approved_by',
        'status_approval',
        'scrap',
        'scrap_note',
        'is_panelbox_inspection',
        'panelbox_inspection_id',
    ];
    protected $casts = [
        'inspection_at' => 'datetime',
        'due_date' => 'date',
        'target_completion_date' => 'date',
        'has_findings' => 'boolean',
        'is_panelbox_inspection' => 'boolean',
    ];

    public function inventory()
    {
        return $this->belongsTo(
            InvAp::class,
            'inv_ap_id'
        );
    }

    public function inspections()
    {
        return $this->hasMany(
            InspeksiAccessPoint::class,
            'inv_ap_id'
        );
    }
}
