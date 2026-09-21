<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VhmsUnitConditionPeriod extends Model
{
    protected $fillable = ['list_unit_id', 'vhms_not_update_case_id', 'condition', 'started_at', 'ended_at', 'note', 'created_by', 'updated_by'];

    protected function casts(): array { return ['started_at' => 'datetime', 'ended_at' => 'datetime']; }

    public function unit(): BelongsTo { return $this->belongsTo(ListUnit::class, 'list_unit_id'); }
    public function notUpdateCase(): BelongsTo { return $this->belongsTo(VhmsNotUpdateCase::class, 'vhms_not_update_case_id'); }
}
