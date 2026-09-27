<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\InvStarlink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryStarlinkController extends Controller
{
    use AuthorizesInventorySite;

    private const ALLOWED_ROLES = ['ict_developer', 'ict_ho', 'ict_group_leader', 'ict_technician', 'ict_admin'];

    private const ALLOWED_SITES = [
        'HO', 'BA', 'MIFA', 'MHU', 'ADW', 'AMI', 'PIK', 'BGE', 'BIB',
        'IPT', 'MLP', 'MIP', 'VIB', 'SBS', 'SKS', 'MAC', 'DMP',
    ];

    private const ASSET_TYPES = ['SEWA', 'INVENTARIS'];

    private const DEVICE_TYPES = ['MINI', 'REGULER'];

    private const SUBSCRIPTION_TYPES = ['RESIDENTIAL', 'ROAM', 'BUSINESS'];

    private const STATUSES = ['READY_USED', 'READY_STANBY', 'BREAKDOWN', 'SCRAP'];

    public function options(Request $request): JsonResponse
    {
        $site = $this->authorizedStarlinkSite($request);

        return response()->json(['data' => [
            'asset_types' => self::ASSET_TYPES,
            'device_types' => self::DEVICE_TYPES,
            'subscription_types' => self::SUBSCRIPTION_TYPES,
            'statuses' => self::STATUSES,
            'default_site' => $site,
            'can_access_other_sites' => $this->canAccessOtherSites($request),
        ]]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:20', Rule::in(self::ALLOWED_SITES)],
        ]);
        $site = $this->authorizedStarlinkSite($request, $validated['site'] ?? null);
        $maxId = $this->nextMaxId($site);

        return response()->json(['data' => [
            'inventory_number' => $this->inventoryNumber($site, $maxId),
            'max_id' => $maxId,
            'site' => $site,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());
        $site = $this->authorizedStarlinkSite($request, $validated['site'] ?? null);

        $starlinks = InvStarlink::query()
            ->where('site', $site)
            ->when($validated['asset_type'] ?? null, fn (Builder $query, string $value) => $query->where('asset_type', $value))
            ->when($validated['device_type'] ?? null, fn (Builder $query, string $value) => $query->where('device_type', $value))
            ->when($validated['subscription_type'] ?? null, fn (Builder $query, string $value) => $query->where('subscription_type', $value))
            ->when($validated['status'] ?? null, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    foreach ($this->searchableColumns() as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($starlinks);
    }

    public function show(Request $request, string $starlink): JsonResponse
    {
        return response()->json(['data' => $this->findAuthorized($request, $starlink)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedStarlinkSite($request, $validated['site'] ?? null);
        unset($validated['site']);
        $validated = $this->normalize($validated);

        $starlink = DB::transaction(function () use ($validated, $site): InvStarlink {
            $maxId = $this->nextMaxId($site, true);

            return InvStarlink::create([
                ...$validated,
                'max_id' => $maxId,
                'inventory_number' => $this->inventoryNumber($site, $maxId),
                'site' => $site,
            ]);
        });

        return response()->json([
            'message' => 'Inventory Starlink berhasil dibuat.',
            'data' => $starlink,
        ], 201);
    }

    public function update(Request $request, string $starlink): JsonResponse
    {
        $record = $this->findAuthorized($request, $starlink);
        $validated = $this->normalize($request->validate($this->rules(true)));
        $record->update($validated);

        return response()->json([
            'message' => 'Inventory Starlink berhasil diperbarui.',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy(Request $request, string $starlink): JsonResponse
    {
        $record = $this->findAuthorized($request, $starlink);
        abort_if($request->user()->role === 'ict_technician', 403, 'Teknisi tidak diizinkan menghapus Inventory Starlink.');
        $record->delete();

        return response()->json(['message' => 'Inventory Starlink berhasil dihapus.']);
    }

    private function filterRules(): array
    {
        return [
            'site' => ['nullable', 'string', 'max:20', Rule::in(self::ALLOWED_SITES)],
            'search' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(self::ASSET_TYPES)],
            'device_type' => ['nullable', Rule::in(self::DEVICE_TYPES)],
            'subscription_type' => ['nullable', Rule::in(self::SUBSCRIPTION_TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'site' => $updating ? ['prohibited'] : ['nullable', 'string', 'max:20', Rule::in(self::ALLOWED_SITES)],
            'inventory_number' => ['prohibited'],
            'max_id' => ['prohibited'],
            'device_name' => [...$required, 'string', 'max:150'],
            'asset_type' => [...$required, Rule::in(self::ASSET_TYPES)],
            'kit_number' => ['nullable', 'string', 'max:100'],
            'device_type' => [...$required, Rule::in(self::DEVICE_TYPES)],
            'subscription_type' => [...$required, Rule::in(self::SUBSCRIPTION_TYPES)],
            'location' => [...$required, 'string', 'max:255'],
            'mikrotik_ip' => ['nullable', 'ip'],
            'mikrotik_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function searchableColumns(): array
    {
        return [
            'device_name', 'inventory_number', 'kit_number', 'location',
            'mikrotik_ip', 'mikrotik_mac', 'remark',
        ];
    }

    private function normalize(array $validated): array
    {
        if (array_key_exists('mikrotik_mac', $validated) && $validated['mikrotik_mac'] !== null) {
            $validated['mikrotik_mac'] = strtoupper($validated['mikrotik_mac']);
        }

        return $validated;
    }

    private function nextMaxId(string $site, bool $lock = false): int
    {
        $query = InvStarlink::withTrashed()->where('site', $site);

        if ($lock) {
            $query->lockForUpdate();
        }

        return ((int) $query->max('max_id')) + 1;
    }

    private function inventoryNumber(string $site, int $maxId): string
    {
        return sprintf('PPA%sSL%03d', strtoupper($site), $maxId);
    }

    private function findAuthorized(Request $request, string $id): InvStarlink
    {
        $starlink = InvStarlink::query()->findOrFail($id);
        $this->authorizedStarlinkSite($request, $starlink->site);

        return $starlink;
    }

    private function authorizedStarlinkSite(Request $request, ?string $requestedSite = null): string
    {
        abort_unless(
            in_array($request->user()->role, self::ALLOWED_ROLES, true),
            403,
            'Anda tidak memiliki akses ke Inventory Starlink.'
        );

        $site = $this->authorizedSite($request, $requestedSite);
        abort_unless(in_array($site, self::ALLOWED_SITES, true), 422, 'Site Inventory Starlink tidak valid.');

        return $site;
    }
}
