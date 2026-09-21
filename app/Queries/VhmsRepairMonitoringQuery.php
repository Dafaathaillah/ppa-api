<?php

namespace App\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VhmsRepairMonitoringQuery
{
    public function get(
        string $site,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $shift = null,
    ): Collection {
        $query = DB::table('unit_vhms_repairs as repairs')
            ->join('list_unit as units', 'units.id', '=', 'repairs.list_unit_id')
            ->whereRaw('UPPER(TRIM(units.site)) = ?', [strtoupper(trim($site))])
            ->select([
                'repairs.id',
                'repairs.created_at',
                'repairs.operational_date',
                'repairs.shift',
                'repairs.status',
                'repairs.root_cause',
                'repairs.action',
                'repairs.repair_note',
                'repairs.checking_by',
                'repairs.updated_by',
                'units.site',
                'units.cn',
                'units.sn',
            ]);

        if ($startDate) {
            $query->whereDate('repairs.operational_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('repairs.operational_date', '<=', $endDate);
        }
        if ($shift) {
            $query->where('repairs.shift', $shift);
        }

        return $query->orderByDesc('repairs.created_at')->limit(250)->get();
    }
}
