<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LatestVhmsProblemUnits
{
    private const SORTABLE = [
        'cn' => 'units.cn', 'sn' => 'units.sn', 'model' => 'units.model',
        'ip_wirelless' => 'units.ip_wirelless', 'mac_wirelless' => 'units.mac_wirelless',
        'tipe_wirelless' => 'units.tipe_wirelless', 'status' => 'units.status',
        'status_vhms' => 'units.status_vhms', 'last_download' => 'units.last_download',
        'last_operation' => 'units.last_operation', 'remark' => 'units.remark',
    ];

    public function forSite(string $site, ?string $search = null, bool $onlyNotReady = false, string $sort = 'cn', string $direction = 'asc'): Collection
    {
        return $this->queryForSite($site, $search, $onlyNotReady, $sort, $direction)->get();
    }

    public function queryForSite(string $site, ?string $search = null, bool $onlyNotReady = false, string $sort = 'cn', string $direction = 'asc'): Builder
    {
        $site = strtolower(trim($site));

        $query = DB::table('list_unit as units')
            ->leftJoin('historical_vhms_downloads as kpi', function ($join): void {
                $join->on('kpi.id', '=', DB::raw('(
                    SELECT newer.id
                    FROM historical_vhms_downloads AS newer
                    WHERE newer.site_normalized = units.site_normalized
                      AND newer.sn_normalized = units.sn_normalized
                      AND newer.cn_normalized = units.cn_normalized
                    ORDER BY newer.date DESC, newer.id DESC
                    LIMIT 1
                )'));
            })
            ->leftJoin('unit_vhms_repairs as latest_repair', function ($join): void {
                $join->on('latest_repair.id', '=', DB::raw('(
                    SELECT repair.id
                    FROM unit_vhms_repairs AS repair
                    WHERE repair.list_unit_id = units.id
                    ORDER BY repair.created_at DESC, repair.id DESC
                    LIMIT 1
                )'));
            })
            ->leftJoin('vhms_unit_condition_periods as latest_condition', function ($join): void {
                $join->on('latest_condition.id', '=', DB::raw('(
                    SELECT condition_period.id
                    FROM vhms_unit_condition_periods AS condition_period
                    WHERE condition_period.list_unit_id = units.id
                      AND condition_period.ended_at IS NULL
                    ORDER BY condition_period.started_at DESC, condition_period.id DESC
                    LIMIT 1
                )'));
            })
            ->where('units.site_normalized', $site)
            ->whereNotNull('units.vhms_api_seen_at')
            ->whereNotNull('units.sn_normalized')
            ->where('units.sn_normalized', '<>', '')
            ->whereNotNull('units.cn_normalized')
            ->where('units.cn_normalized', '<>', '')
            ->select(
                'units.*',
                'kpi.id as kpi_id',
                DB::raw('COALESCE(kpi.date, units.vhms_api_seen_at) as kpi_date'),
                'kpi.status as kpi_status',
                'kpi.feedback as kpi_feedback',
                'latest_repair.status as latest_repair_status',
                'latest_condition.started_at as condition_started_at'
            );

        if ($onlyNotReady) {
            $query->whereRaw("COALESCE(UPPER(TRIM(units.status)), '') <> 'READY_USED'");
        } else {
            $query->whereRaw("LOWER(TRIM(units.status_vhms)) = 'not_update'")
                ->whereRaw("COALESCE(UPPER(TRIM(units.status)), '') <> 'READY_STANDBY'");
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $keyword = '%'.strtolower($search).'%';
            $query->where(function (Builder $filter) use ($keyword): void {
                $filter->whereRaw('LOWER(COALESCE(units.cn, ?)) LIKE ?', ['', $keyword])
                    ->orWhereRaw('LOWER(COALESCE(units.sn, ?)) LIKE ?', ['', $keyword])
                    ->orWhereRaw('LOWER(COALESCE(units.model, ?)) LIKE ?', ['', $keyword])
                    ->orWhereRaw('LOWER(COALESCE(units.status_vhms, ?)) LIKE ?', ['', $keyword])
                    ->orWhereRaw('LOWER(COALESCE(units.remark, ?)) LIKE ?', ['', $keyword]);
            });
        }

        $sortColumn = self::SORTABLE[$sort] ?? self::SORTABLE['cn'];
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sortColumn, $direction)->orderBy('units.id');
    }
}
