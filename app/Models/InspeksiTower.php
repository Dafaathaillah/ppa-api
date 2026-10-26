<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspeksiTower extends Model
{
    use HasFactory;

    protected $fillable = [
        'inv_tower_id',
        'pica_number',
        'created_date',
        'month',
        'year',
        'inspection_status',
        'inspection_at',
        'inspector',
        'worthiness',
        'kelayakan',
        'condition',
        'device_status',
        'inventory_status',
        'inspection_image',
        'checklist_results_list',
        'list_results_remark',
        'pic',
        'crew',
        'conditions',
        'physic_condition_tower',
        'physic_condition_tower_text',
        'grounding_tower',
        'grounding_tower_text',
        'fence_tower',
        'fence_tower_text',
        'list_of_needs',
        'findings',
        'findings_image',
        'findings_action',
        'action_image',
        'findings_status',
        'remarks',
        'due_date',
        'approved_by',
        'status_approval',
    ];

    protected $casts = [
        'inspection_at' => 'datetime',
        'due_date' => 'date',
    ];

    public function tower()
    {
        return $this->belongsTo(InvTower::class, 'inv_tower_id');
    }
}
