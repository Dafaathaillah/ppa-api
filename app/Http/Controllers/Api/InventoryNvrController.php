<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\InvNvr;
use App\Support\Api\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryNvrController extends Controller
{
    public function syncChannels(Request $request): JsonResponse
    {
        $site = SiteContext::resolve($request);
        SiteContext::authorizeWrite($request, $site);
        $updated = DB::transaction(function () use ($site): int {
            $count = 0;
            foreach (InvNvr::with(['cctvs', 'viewOnlyCctvs'])->where('site', $site)->cursor() as $nvr) {
                $used = $nvr->status === 'VIEW_ONLY' ? $nvr->viewOnlyCctvs->count() : $nvr->cctvs->count();
                $nvr->update(['channel_used' => $used, 'channel_free' => max(0, (int) $nvr->jumlah_channel - $used)]);
                $count++;
            }
            return $count;
        });

        return response()->json(['message' => "Berhasil mensinkronisasi {$updated} NVR untuk site {$site}.", 'updated' => $updated]);
    }

    use AuthorizesInventorySite;

    private const STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $data = InvNvr::query()
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    foreach (['inventory_number', 'number_asset_ho', 'nvr_name', 'nvr_brand', 'nvr_type', 'mac_address', 'ip_address', 'location'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($data);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => self::STATUSES,
            'companies' => ['PPA', 'AMM'],
            'default_site' => strtoupper((string) $request->user()->site),
            'can_access_other_sites' => $this->canAccessOtherSites($request),
        ]]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'company' => ['required', Rule::in(['PPA', 'AMM'])],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'inventory_number' => $this->generateNumber($site, $validated['company']),
            'site' => $site,
            'company' => $validated['company'],
        ]]);
    }

    public function show(Request $request, string $nvr): JsonResponse
    {
        return response()->json(['data' => $this->findAuthorized($request, $nvr)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        unset($validated['site'], $validated['company']);
        $this->validateChannels($validated);

        $nvr = DB::transaction(function () use ($validated, $site, $request) {
            $this->ensureInventoryNumberAvailable($validated['inventory_number']);

            return InvNvr::create([
                ...$validated,
                'site' => $site,
                'last_update_at' => now(),
                'last_update_by' => $request->user()->name,
            ]);
        });

        return response()->json(['message' => 'Inventory NVR berhasil dibuat.', 'data' => $nvr], 201);
    }

    public function update(Request $request, string $nvr): JsonResponse
    {
        $record = $this->findAuthorized($request, $nvr);
        $validated = $request->validate($this->rules(true));
        unset($validated['site'], $validated['company'], $validated['inventory_number']);
        $this->validateChannels([...$record->toArray(), ...$validated]);

        DB::transaction(function () use ($record, $validated, $request) {
            $record->update([
                ...$validated,
                'last_update_at' => now(),
                'last_update_by' => $request->user()->name,
            ]);
        });

        return response()->json(['message' => 'Inventory NVR berhasil diperbarui.', 'data' => $record->fresh()]);
    }

    public function destroy(Request $request, string $nvr): JsonResponse
    {
        $record = $this->findAuthorized($request, $nvr);
        $record->delete();

        return response()->json(['message' => 'Inventory NVR berhasil dihapus.']);
    }

    private function filterRules(): array
    {
        return [
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'site' => ['nullable', 'string', 'max:25'],
            'company' => ['nullable', Rule::in(['PPA', 'AMM'])],
            'inventory_number' => [...$required, 'string', 'max:255'],
            'number_asset_ho' => ['nullable', 'string', 'max:255'],
            'nvr_name' => [...$required, 'string', 'max:255'],
            'nvr_brand' => ['nullable', 'string', 'max:255'],
            'nvr_type' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'jumlah_hdd' => ['nullable', 'string', 'max:255'],
            'total_capacity' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'jumlah_channel' => [...$required, 'integer', 'min:0'],
            'channel_used' => ['nullable', 'integer', 'min:0'],
            'channel_free' => ['nullable', 'integer', 'min:0'],
            'inspection_remark' => ['nullable', 'string'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'remark' => ['nullable', 'string'],
            'date_of_inventory' => ['nullable', 'date'],
        ];
    }

    private function validateChannels(array $data): void
    {
        $total = (int) ($data['jumlah_channel'] ?? 0);
        $used = (int) ($data['channel_used'] ?? 0);
        $free = (int) ($data['channel_free'] ?? 0);

        if ($used > $total || $free > $total || $used + $free > $total) {
            throw ValidationException::withMessages([
                'channel_used' => ['Jumlah channel used dan free tidak boleh melebihi jumlah channel.'],
            ]);
        }
    }

    private function generateNumber(string $site, string $company): string
    {
        $prefix = $company.$site.'NVR';
        $last = InvNvr::withTrashed()->where('inventory_number', 'like', $prefix.'%')->orderByDesc('inventory_number')->value('inventory_number');
        preg_match('/(\d+)$/', (string) $last, $matches);

        return $prefix.str_pad(((int) ($matches[1] ?? 0)) + 1, 3, '0', STR_PAD_LEFT);
    }

    private function ensureInventoryNumberAvailable(string $number): void
    {
        if (InvNvr::withTrashed()->where('inventory_number', $number)->exists()) {
            throw ValidationException::withMessages(['inventory_number' => ['Nomor inventory sudah digunakan.']]);
        }
    }

    private function findAuthorized(Request $request, string $id): InvNvr
    {
        $record = InvNvr::query()->findOrFail($id);
        $this->authorizedSite($request, $record->site);

        return $record;
    }
}
