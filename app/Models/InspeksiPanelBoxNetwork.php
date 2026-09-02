<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspeksiPanelBoxNetwork extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'inspeksi_panel_box_networks';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'inv_panel_box_id',
        'created_date',
        'month',
        'year',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'due_date',

        'inspection_status',
        'cleanliness',
        'conditions',
        'remarks',
        'cable_arrangement',
        'inspection_by',
        'inventory_status',
        'inspection_at',

        'approved_by',
        'status_approval',
        'pica_number',
        'site',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'due_date' => 'date',
        'inspection_at' => 'datetime',
    ];

    /**
     * Relasi ke Inventory Panel Box
     */
    public function panelBox()
    {
        return $this->belongsTo(
            InvPanelBox::class,
            'inv_panel_box_id'
        );
    }

    public function devices()
    {
        return $this->hasMany(
            InspeksiPanelBoxDevice::class,
            'inspeksi_panel_box_id'
        );
    }
}
