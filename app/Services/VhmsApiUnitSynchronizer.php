<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class VhmsApiUnitSynchronizer
{
    public const API_TIMEOUT_SECONDS = 30;
    public const SITES = ['BA', 'MIFA', 'MHU', 'ADW', 'AMI', 'PIK', 'BGE', 'BIB', 'IPT', 'MLP', 'MIP', 'VIB', 'SBS', 'SKS', 'MAC', 'DMP'];

    public function endpoint(string $site): string
    {
        return 'https://api50.ppa-'.strtolower(trim($site)).'.net/vhms/downloader/monitoring';
    }

    public function rawPayload(string $site): array
    {
        $response = Http::connectTimeout(5)
            ->timeout(self::API_TIMEOUT_SECONDS)
            ->acceptJson()
            ->get($this->endpoint($site));

        $response->throw();

        return $response->json();
    }

    public function sync(string $site, bool $force = false): array
    {
        $site = strtoupper(trim($site));
        if (! in_array($site, self::SITES, true)) {
            return $this->metadata('error', null, 'Site VHMS tidak didukung.', 0);
        }

        $freshKey = "vhms:api-units:{$site}:fresh";
        $metaKey = "vhms:api-units:{$site}:meta";
        if (! $force && Cache::has($freshKey)) {
            return Cache::get($metaKey, $this->metadata('cached', null, null, 0));
        }

        try {
            return Cache::lock("vhms:api-units:{$site}:lock", 60)->block(2, function () use ($site, $force, $freshKey, $metaKey): array {
                if (! $force && Cache::has($freshKey)) {
                    return Cache::get($metaKey, $this->metadata('cached', null, null, 0));
                }

                $response = Http::connectTimeout(5)
                    ->timeout(self::API_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->endpoint($site));

                if (! $response->successful() || ! is_array($response->json('data'))) {
                    $previous = Cache::get($metaKey, []);
                    return $this->metadata(
                        'stale',
                        $previous['last_synced_at'] ?? null,
                        'API VHMS tidak dapat diperbarui. Menampilkan snapshot terakhir.',
                        0,
                        $previous['not_update'] ?? $this->currentNotUpdateCount($site),
                    );
                }

                $units = $this->normalizePayload($response->json(), $site);
                $synced = $this->persist($site, $units);
                $notUpdate = collect($units)->where('status_vhms', 'not_update')->count();
                $meta = $this->metadata('fresh', now()->toIso8601String(), null, $synced, $notUpdate);
                Cache::put($freshKey, true, now()->addMinutes(5));
                Cache::put($metaKey, $meta, now()->addDay());

                return $meta;
            });
        } catch (Throwable $exception) {
            report($exception);
            $previous = Cache::get($metaKey, []);
            return $this->metadata(
                'stale',
                $previous['last_synced_at'] ?? null,
                'API VHMS sedang sibuk. Menampilkan snapshot terakhir.',
                0,
                $previous['not_update'] ?? $this->currentNotUpdateCount($site),
            );
        }
    }

    public function isFresh(string $site): bool
    {
        return Cache::has('vhms:api-units:'.strtoupper(trim($site)).':fresh');
    }

    public function currentMetadata(string $site): array
    {
        $site = strtoupper(trim($site));
        $meta = Cache::get("vhms:api-units:{$site}:meta", []);
        return $this->metadata(
            $this->isFresh($site) ? 'cached' : 'refreshing',
            $meta['last_synced_at'] ?? null,
            $this->isFresh($site) ? null : 'Menampilkan snapshot terakhir. Gunakan tombol Sync API untuk mengambil pembaruan.',
            0,
            $this->currentNotUpdateCount($site),
        );
    }

    public function normalizePayload(array $payload, string $site): array
    {
        $normalized = [];
        foreach (($payload['data'] ?? []) as $category => $units) {
            if (! is_array($units)) continue;
            foreach ($units as $unit) {
                if (! is_array($unit)) continue;
                $sn = trim((string) ($unit['sn'] ?? ''));
                $cn = trim((string) ($unit['cn'] ?? ''));
                if ($sn === '' && $cn === '') continue;
                $rawStatus = $unit['status_vhms'] ?? $unit['api_status'] ?? null;
                $status = is_string($rawStatus) && trim($rawStatus) !== ''
                    ? strtolower(str_replace([' ', '-'], '_', trim($rawStatus)))
                    : match ((int) ($unit['color'] ?? -1)) {
                        0 => 'update', 1 => 'waiting', 2 => 'not_update', default => 'unknown',
                    };
                $normalized[] = [
                    'type' => trim((string) $category) ?: null,
                    'model' => isset($unit['model']) ? trim((string) $unit['model']) : null,
                    'sn' => $sn ?: null,
                    'cn' => $cn ?: null,
                    'site' => strtoupper(trim($site)),
                    'status_vhms' => $status,
                    'last_download' => $this->normalizeDate($unit['last_download'] ?? null),
                    'last_operation' => $this->normalizeDate($unit['last_operation'] ?? null),
                ];
            }
        }

        return $normalized;
    }

    private function persist(string $site, array $units): int
    {
        return DB::transaction(function () use ($site, $units): int {
            $existing = DB::table('list_unit')
                ->whereRaw('UPPER(TRIM(site)) = ?', [$site])
                ->get(['id', 'sn', 'cn']);
            $bySn = $existing->filter(fn ($row) => trim((string) $row->sn) !== '')->keyBy(fn ($row) => strtolower(trim($row->sn)));
            $byCn = $existing->filter(fn ($row) => trim((string) $row->cn) !== '')->keyBy(fn ($row) => strtolower(trim($row->cn)));
            $now = now();

            DB::table('list_unit')->whereRaw('UPPER(TRIM(site)) = ?', [$site])->update(['vhms_api_seen_at' => null]);

            collect($units)->map(function (array $unit) use ($bySn, $byCn, $now): array {
                $match = $unit['sn'] ? $bySn->get(strtolower($unit['sn'])) : null;
                $match ??= $unit['cn'] ? $byCn->get(strtolower($unit['cn'])) : null;
                return ['id' => $match?->id, ...$unit, 'vhms_api_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            })->chunk(500)->each(function ($chunk): void {
                DB::table('list_unit')->upsert(
                    $chunk->all(),
                    ['id'],
                    ['type', 'model', 'sn', 'cn', 'site', 'status_vhms', 'last_download', 'last_operation', 'vhms_api_seen_at', 'updated_at'],
                );
            });

            return count($units);
        });
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function metadata(string $status, ?string $lastSyncedAt, ?string $message, int $synced, int $notUpdate = 0): array
    {
        return [
            'status' => $status,
            'last_synced_at' => $lastSyncedAt,
            'message' => $message,
            'synced' => $synced,
            'not_update' => $notUpdate,
        ];
    }

    private function currentNotUpdateCount(string $site): int
    {
        return DB::table('list_unit')
            ->whereRaw('UPPER(TRIM(site)) = ?', [strtoupper(trim($site))])
            ->whereNotNull('vhms_api_seen_at')
            ->whereRaw("LOWER(TRIM(status_vhms)) = 'not_update'")
            ->count();
    }
}
