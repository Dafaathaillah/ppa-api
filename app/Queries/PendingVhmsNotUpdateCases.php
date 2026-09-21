<?php

namespace App\Queries;

use App\Models\VhmsNotUpdateCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PendingVhmsNotUpdateCases
{
    private const SORTABLE = [
        'cn' => 'units.cn', 'sn' => 'units.sn', 'model' => 'units.model',
        'api_status' => 'cases.api_status', 'source_at' => 'cases.source_at',
        'workflow_status' => 'cases.workflow_status',
    ];

    public function query(string $site, array $filters = []): Builder
    {
        $query = DB::table('vhms_not_update_cases as cases')
            ->join('list_unit as units', 'units.id', '=', 'cases.list_unit_id')
            ->where('cases.site', strtoupper(trim($site)))
            ->select('cases.id', 'cases.api_status', 'cases.source_at', 'cases.workflow_status',
                'cases.operational_condition', 'cases.include_in_breakdown', 'cases.confirmed_by',
                'cases.confirmed_at', 'units.id as unit_id', 'units.cn', 'units.sn', 'units.model');

        if (! empty($filters['workflow_status'])) $query->where('cases.workflow_status', $filters['workflow_status']);
        else $query->where('cases.workflow_status', VhmsNotUpdateCase::PENDING_CONFIRMATION);
        if (! empty($filters['condition'])) $query->where('cases.operational_condition', $filters['condition']);
        if (! empty($filters['date_from'])) $query->whereDate('cases.source_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('cases.source_at', '<=', $filters['date_to']);
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $keyword = '%'.strtolower($search).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(COALESCE(units.cn, ?)) LIKE ?', ['', $keyword])
                ->orWhereRaw('LOWER(COALESCE(units.sn, ?)) LIKE ?', ['', $keyword])
                ->orWhereRaw('LOWER(COALESCE(units.model, ?)) LIKE ?', ['', $keyword]));
        }
        $sort = (string) ($filters['sort'] ?? 'source_at');
        $sortColumn = self::SORTABLE[$sort] ?? self::SORTABLE['source_at'];
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        return $query->orderBy($sortColumn, $direction)->orderByDesc('cases.id');
    }
}
