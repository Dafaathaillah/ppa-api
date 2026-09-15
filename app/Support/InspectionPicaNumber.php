<?php

namespace App\Support;

use App\Models\PicaInspeksi;

class InspectionPicaNumber
{
    public static function next(string $site, string $deviceCode): string
    {
        $prefix = sprintf('%s/PICA/%s/', strtoupper($site), strtoupper($deviceCode));
        $highest = PicaInspeksi::query()
            ->where('site', strtoupper($site))
            ->where('pica_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('pica_number')
            ->reduce(function (int $maximum, ?string $number) use ($prefix): int {
                if (! is_string($number) || ! str_starts_with($number, $prefix)) {
                    return $maximum;
                }

                $suffix = substr($number, strlen($prefix));

                return ctype_digit($suffix) ? max($maximum, (int) $suffix) : $maximum;
            }, 0);

        return $prefix.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}
