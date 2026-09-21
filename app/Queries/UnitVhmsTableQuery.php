<?php

namespace App\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UnitVhmsTableQuery
{
    private const SUMMARY_TYPES = ['HD', 'PC1250', 'PC2000'];

    private const SORTABLE = ['type', 'model', 'sn', 'cn', 'site', 'status_vhms', 'last_download', 'last_operation', 'ip_wirelless', 'mac_wirelless', 'remark', 'status', 'last_update_at'];

    private const PAGE_SIZES = [10, 25, 50, 100];

    public static function searchPattern(string $search): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            strtolower(trim($search)),
        ).'%';
    }

    public function paginate(string $site, array $params = []): LengthAwarePaginator
    {
        $perPage = (int) ($params['per_page'] ?? 25);
        if (! in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = 25;
        }
        $sort = in_array($params['sort'] ?? '', self::SORTABLE, true) ? $params['sort'] : 'cn';
        $direction = strtolower((string) ($params['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) ($params['search'] ?? ''));

        $query = DB::table('list_unit')
            ->where('site_normalized', strtolower(trim($site)))
            ->whereNotNull('vhms_api_seen_at')
            ->whereRaw("COALESCE(UPPER(TRIM(status)), '') <> 'READY_STANDBY'")
            ->select(['id', 'type', 'model', 'sn', 'cn', 'site', 'status_vhms', 'last_download', 'last_operation', 'ip_wirelless', 'mac_wirelless', 'remark', 'status', 'last_update_at']);

        if ($search !== '') {
            $like = self::searchPattern($search);
            $query->where(function ($nested) use ($like): void {
                foreach (['cn', 'sn', 'model', 'ip_wirelless', 'mac_wirelless'] as $column) {
                    $nested->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(
            $perPage,
            ['*'],
            'page',
            max(1, (int) ($params['page'] ?? 1)),
        );
    }

    public function summary(string $site): array
    {
        $summary = collect(self::SUMMARY_TYPES)->mapWithKeys(fn (string $type): array => [
            $type => ['type' => $type, 'total' => 0, 'update' => 0, 'waiting' => 0, 'not_update' => 0, 'percentage' => 0.0],
        ]);

        DB::table('list_unit')
            ->where('site_normalized', strtolower(trim($site)))
            ->whereNotNull('vhms_api_seen_at')
            ->whereRaw("LOWER(TRIM(status)) = 'ready_used'")
            ->selectRaw('UPPER(TRIM(type)) as unit_type, LOWER(TRIM(status_vhms)) as api_status, COUNT(*) as aggregate')
            ->groupByRaw('UPPER(TRIM(type)), LOWER(TRIM(status_vhms))')
            ->get()
            ->each(function (object $row) use ($summary): void {
                $rawType = (string) $row->unit_type;
                $type = str_starts_with($rawType, 'HD') ? 'HD' : $rawType;
                if (! $summary->has($type)) {
                    return;
                }

                $card = $summary->get($type);
                $count = (int) $row->aggregate;
                $card['total'] += $count;
                if (in_array($row->api_status, ['update', 'waiting', 'not_update'], true)) {
                    $card[$row->api_status] += $count;
                }
                $summary->put($type, $card);
            });

        return $summary->values()->map(function (array $card): array {
            $card['percentage'] = $card['total'] > 0
                ? round((($card['update'] + $card['waiting']) / $card['total']) * 100, 2)
                : 0.0;

            return $card;
        })->all();
    }
}
