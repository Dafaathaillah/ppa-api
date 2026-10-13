<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\PicaInspeksi;
use App\Support\Api\SiteContext;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ProcessesAssetInspection
{
    protected function scopeInspectionAccess(Builder $query, Request $request, ?string $siteColumn = 'site', ?string $relation = null): Builder
    {
        if (SiteContext::canAccessAnySite($request)) {
            return $query;
        }

        $site = SiteContext::resolve($request);

        return $siteColumn
            ? SiteContext::apply($query, $siteColumn, $site)
            : $query->whereHas($relation ?? 'tower', fn (Builder $related) => SiteContext::apply($related, 'site', $site));
    }

    protected function inspectionFilters(Request $request): array
    {
        return $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2500'],
            'inspection_status' => ['nullable', 'string', 'max:255'],
            'status_approval' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    protected function applyFilters(Builder $query, array $filters, ?string $siteColumn = 'site'): Builder
    {
        if ($filters['site'] ?? null) {
            $site = strtoupper($filters['site']);
            $siteColumn ? $query->where($siteColumn, $site) : $query->whereHas('tower', fn (Builder $q) => $q->where('site', $site));
        }

        return $query
            ->when($filters['month'] ?? null, fn (Builder $q, $month) => $q->where('month', $month))
            ->when($filters['year'] ?? null, fn (Builder $q, $year) => $q->where('year', $year))
            ->when($filters['inspection_status'] ?? null, fn (Builder $q, $status) => $q->where('inspection_status', $status))
            ->when($filters['status_approval'] ?? null, fn (Builder $q, $status) => $q->where('status_approval', $status));
    }

    protected function assertCanProcess(Request $request, string $site): void
    {
        abort_unless(
            in_array($request->user()->role, ['ict_technician', 'ict_group_leader'], true)
            && strtoupper((string) $request->user()->site) === strtoupper($site),
            403,
            'Anda hanya dapat memproses inspeksi site sendiri.'
        );
    }

    protected function assertCanApprove(Request $request, string $site): void
    {
        abort_unless(
            $request->user()->role === 'ict_group_leader'
            && strtoupper((string) $request->user()->site) === strtoupper($site),
            403,
            'Group Leader hanya dapat melakukan approval untuk site sendiri.'
        );
    }

    protected function storedPhoto(Request $request, string $field, TowerInspectionPhotoStorage $photos, ?string $existing): ?string
    {
        return $request->hasFile($field) ? $photos->store($request->file($field)) : $existing;
    }

    protected function picaFor(string $inspectionId, string $deviceCode): ?PicaInspeksi
    {
        return PicaInspeksi::where('inspeksi_id', $inspectionId)
            ->where('pica_number', 'like', '%/PICA/'.strtoupper($deviceCode).'/%')
            ->first();
    }

    protected function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
