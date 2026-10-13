<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class OperationalShift
{
    public function __construct(
        public readonly string $shift,
        public readonly string $operationalDate,
    ) {}

    public static function from(CarbonInterface $at): self
    {
        $shift = $at->hour >= 6 && $at->hour < 18 ? 'SHIFT_1' : 'SHIFT_2';
        $date = $shift === 'SHIFT_2' && $at->hour < 6 ? $at->copy()->subDay() : $at;

        return new self($shift, $date->toDateString());
    }
}
