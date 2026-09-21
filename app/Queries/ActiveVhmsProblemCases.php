<?php

namespace App\Queries;

use App\Models\VhmsNotUpdateCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActiveVhmsProblemCases
{
    public function forSite(string $site, ?string $search = null): Collection { return $this->queryForSite($site, $search)->get(); }

    public function queryForSite(string $site, ?string $search = null): Builder
    {
        $query = DB::table('vhms_not_update_cases as cases')->join('list_unit as units', 'units.id', '=', 'cases.list_unit_id')
            ->where('cases.site', strtoupper(trim($site)))->where('cases.include_in_breakdown', true)
            ->whereNull('cases.completed_at')->where('cases.workflow_status', VhmsNotUpdateCase::IN_HANDLING)
            ->select('units.*', 'cases.id as case_id', 'cases.api_status as kpi_status', 'cases.source_at as kpi_date',
                'cases.operational_condition', 'cases.confirmed_at', 'cases.confirmed_by');
        if ($search = trim((string) $search)) {
            $keyword = '%'.strtolower($search).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(COALESCE(units.cn, ?)) LIKE ?', ['', $keyword])
                ->orWhereRaw('LOWER(COALESCE(units.sn, ?)) LIKE ?', ['', $keyword])
                ->orWhereRaw('LOWER(COALESCE(units.model, ?)) LIKE ?', ['', $keyword])
                ->orWhereRaw('LOWER(COALESCE(cases.operational_condition, ?)) LIKE ?', ['', $keyword]));
        }
        return $query->orderBy('units.cn');
    }
}
