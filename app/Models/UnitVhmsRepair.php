<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitVhmsRepair extends Model
{
    protected $fillable = [
        'root_cause_problem_id',
        'status',
        'shift',
        'operational_date',
        'status_vhms',
        'repair_started_at',
        'repair_ended_at',
        'root_cause',
        'action',
        'repair_note',
        'checking_by',
        'updated_by',
        'vhms_not_update_case_id',
    ];

    protected $casts = ['operational_date' => 'date'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ListUnit::class, 'list_unit_id');
    }

    public function notUpdateCase(): BelongsTo
    {
        return $this->belongsTo(VhmsNotUpdateCase::class, 'vhms_not_update_case_id');
    }
}
