<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VhmsRepairHistory
{
    private const SORTABLE = [
        'cn' => 'units.cn', 'model' => 'units.model', 'status' => 'repairs.status',
        'status_vhms' => 'repairs.status_vhms', 'repair_started_at' => 'repairs.repair_started_at',
        'root_cause' => 'repairs.root_cause', 'action' => 'repairs.action',
        'repair_note' => 'repairs.repair_note', 'checking_by' => 'repairs.checking_by',
        'created_at' => 'repairs.created_at',
    ];

    public function queryForSite(string $site, ?string $search = null, string $sort = 'created_at', string $direction = 'desc'): Builder
    {
        $query = DB::table('unit_vhms_repairs as repairs')
            ->join('list_unit as units', 'units.id', '=', 'repairs.list_unit_id')
            ->whereRaw('UPPER(TRIM(units.site)) = ?', [strtoupper(trim($site))])
            ->select(
                'repairs.id', 'repairs.status', 'repairs.status_vhms', 'repairs.root_cause',
                'repairs.action', 'repairs.repair_note', 'repairs.checking_by', 'repairs.updated_by',
                'repairs.repair_started_at', 'repairs.repair_ended_at',
                'repairs.created_at', 'units.site', 'units.cn', 'units.sn', 'units.model'
            );

        if (($search = trim((string) $search)) !== '') {
            $keyword = '%'.strtolower($search).'%';
            $query->where(function (Builder $filter) use ($keyword): void {
                foreach (['units.cn', 'units.sn', 'units.model', 'repairs.status', 'repairs.status_vhms', 'repairs.action', 'repairs.root_cause'] as $column) {
                    $filter->orWhereRaw("LOWER(COALESCE({$column}, ?)) LIKE ?", ['', $keyword]);
                }
            });
        }

        $sortColumn = self::SORTABLE[$sort] ?? self::SORTABLE['created_at'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)->orderByDesc('repairs.id');
    }
}
