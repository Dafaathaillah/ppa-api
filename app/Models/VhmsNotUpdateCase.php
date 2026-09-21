<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VhmsNotUpdateCase extends Model
{
    public const PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    public const CONFIRMED = 'CONFIRMED';
    public const IN_HANDLING = 'IN_HANDLING';
    public const COMPLETED = 'COMPLETED';
    public const CONDITIONS = ['BREAKDOWN', 'READY_USED', 'READY_STANDBY', 'SERVICE', 'CONTROLLER_PROBLEM', 'SCRAP'];

    protected $fillable = [
        'list_unit_id', 'site', 'source_key', 'source_record_id', 'api_status', 'source_at',
        'workflow_status', 'operational_condition', 'include_in_breakdown', 'confirmation_note',
        'confirmed_by', 'confirmed_at', 'completed_at',
        'initial_root_cause_problem_id',
    ];

    protected function casts(): array
    {
        return [
            'source_at' => 'datetime', 'include_in_breakdown' => 'boolean',
            'confirmed_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo { return $this->belongsTo(ListUnit::class, 'list_unit_id'); }
    public function conditionPeriods(): HasMany { return $this->hasMany(VhmsUnitConditionPeriod::class); }
    public function repairs(): HasMany { return $this->hasMany(UnitVhmsRepair::class); }
}
