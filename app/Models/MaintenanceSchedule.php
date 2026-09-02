<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MaintenanceSchedule extends Model
{
    protected $fillable = ['is_active', 'started_at', 'ends_at', 'message', 'activated_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        // Keep public pages available while a deployment is running before
        // the maintenance migration has been applied.
        if (! Schema::hasTable((new static)->getTable())) {
            return new static(['is_active' => false]);
        }

        return self::query()->oldest('id')->first()
            ?? self::create(['is_active' => false]);
    }

    public function activeNow(): bool
    {
        if (!$this->is_active || !$this->started_at || !$this->ends_at) {
            return false;
        }

        if ($this->started_at->isFuture()) {
            return false;
        }

        if ($this->ends_at->isPast()) {
            $this->update(['is_active' => false]);
            return false;
        }

        return true;
    }
}
