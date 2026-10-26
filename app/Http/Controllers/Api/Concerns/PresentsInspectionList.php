<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait PresentsInspectionList
{
    protected function applyInspectionSearch(
        Builder $query,
        ?string $search,
        array $inspectionColumns,
        string $inventoryRelation,
        array $inventoryColumns
    ): Builder {
        if (! filled($search)) {
            return $query;
        }

        $inspectionColumns = array_values(array_intersect(
            $inspectionColumns,
            Schema::getColumnListing($query->getModel()->getTable())
        ));
        $inventoryTable = $query->getModel()->{$inventoryRelation}()->getRelated()->getTable();
        $inventoryColumns = array_values(array_intersect(
            $inventoryColumns,
            Schema::getColumnListing($inventoryTable)
        ));
        if ($inspectionColumns === [] && $inventoryColumns === []) {
            return $query->whereRaw('1 = 0');
        }

        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(function (Builder $query) use ($term, $inspectionColumns, $inventoryRelation, $inventoryColumns): void {
            foreach ($inspectionColumns as $index => $column) {
                $index === 0
                    ? $query->where($column, 'like', $term)
                    : $query->orWhere($column, 'like', $term);
            }

            if ($inventoryColumns === []) {
                return;
            }

            $method = $inspectionColumns === [] ? 'whereHas' : 'orWhereHas';
            $query->{$method}($inventoryRelation, function (Builder $inventory) use ($term, $inventoryColumns): void {
                foreach ($inventoryColumns as $index => $column) {
                    $index === 0
                        ? $inventory->where($column, 'like', $term)
                        : $inventory->orWhere($column, 'like', $term);
                }
            });
        });
    }

    protected function approvalResource(Model $inspection): array
    {
        $status = in_array(strtolower(trim((string) $inspection->status_approval)), ['approve', 'approved'], true)
            ? 'approved'
            : null;

        return [
            'status_approval' => $status,
            'approved_by' => $status === null ? null : $inspection->approved_by,
        ];
    }

    protected function inspectionResource(Model $inspection): array
    {
        return [
            ...$inspection->toArray(),
            ...$this->approvalResource($inspection),
        ];
    }
}
