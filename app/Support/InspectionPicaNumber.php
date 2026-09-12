<?php

namespace App\Support;

use App\Models\PicaInspeksi;

class InspectionPicaNumber
{
    public static function next(string $site, string $prefix): string
    {
        $normalizedSite = strtoupper($site);
        $numbers = PicaInspeksi::where('site', $site)
            ->where('pica_number', 'like', "{$normalizedSite}/PICA/{$prefix}/%")
            ->lockForUpdate()
            ->pluck('pica_number')
            ->map(function ($number): int {
                preg_match('/(\d+)$/', (string) $number, $matches);

                return (int) ($matches[1] ?? 0);
            });

        return sprintf('%s/PICA/%s/%03d', $normalizedSite, $prefix, ($numbers->max() ?? 0) + 1);
    }
}
