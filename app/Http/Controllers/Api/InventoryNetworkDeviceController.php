<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

abstract class InventoryNetworkDeviceController extends Controller
{
    use AuthorizesInventorySite;

    private const STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'];

    abstract protected function modelClass(): string;

    abstract protected function deviceCode(): string;

    abstract protected function label(): string;

    protected function hasFrequency(): bool
    {
        return false;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'company' => ['nullable', Rule::in(['PPA', 'AMM'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $model = $this->modelClass();

        $data = $model::query()
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['company'] ?? null, fn ($query, $company) => $query->where('inventory_number', 'like', $company.'%'))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    foreach (['device_name', 'inventory_number', 'asset_ho_number', 'serial_number', 'mac_address', 'ip_address', 'device_brand', 'device_type', 'device_model', 'location'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Model $device) => $this->resource($device));

        return response()->json($data);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => self::STATUSES,
            'companies' => ['PPA', 'AMM'],
            'default_site' => strtoupper((string) $request->user()->site),
            'can_access_other_sites' => $this->canAccessOtherSites($request),
            'supports_frequency' => $this->hasFrequency(),
        ]]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'company' => ['required', Rule::in(['PPA', 'AMM'])],
            'device_id' => ['nullable', 'uuid'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        if ($deviceId = ($validated['device_id'] ?? null)) {
            $device = $this->findAuthorized($request, $deviceId);
            if ($this->inferCompany($device->inventory_number) === $validated['company']) {
                return response()->json(['data' => [
                    'inventory_number' => $device->inventory_number,
                    'company' => $validated['company'],
                    'site' => $site,
                    'reused_existing_number' => true,
                ]]);
            }
        }

        return response()->json(['data' => [
            'inventory_number' => $this->generateNumber($site, $validated['company']),
            'company' => $validated['company'],
            'site' => $site,
            'reused_existing_number' => false,
        ]]);
    }

    public function show(Request $request, string $device): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->findAuthorized($request, $device))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $company = $validated['company'] ?? null;
        unset($validated['site'], $validated['company']);

        $device = DB::transaction(function () use ($validated, $site, $company) {
            $model = $this->modelClass();
            $validated['inventory_number'] = $validated['inventory_number'] ?? $this->generateNumber($site, $company);
            $this->validateInventoryNumber($site, $company, $validated['inventory_number']);
            $this->ensureInventoryNumberAvailable($validated['inventory_number']);
            $validated['max_id'] = ((int) $model::withTrashed()->lockForUpdate()->max('max_id')) + 1;

            return $model::create([...$validated, 'site' => $site]);
        });

        return response()->json([
            'message' => 'Inventory '.$this->label().' berhasil dibuat.',
            'data' => $this->resource($device),
        ], 201);
    }

    public function update(Request $request, string $device): JsonResponse
    {
        $record = $this->findAuthorized($request, $device);
        $validated = $request->validate($this->rules(true));
        $company = $validated['company'] ?? null;
        unset($validated['site'], $validated['company'], $validated['max_id']);

        DB::transaction(function () use ($record, $validated, $company) {
            if ($company) {
                $currentCompany = $this->inferCompany($record->inventory_number);
                if ($company !== $currentCompany) {
                    $validated['inventory_number'] = $validated['inventory_number']
                        ?? $this->generateNumber($record->site, $company);
                } else {
                    $validated['inventory_number'] = $record->inventory_number;
                }
            } else {
                unset($validated['inventory_number']);
            }

            if (isset($validated['inventory_number']) && $validated['inventory_number'] !== $record->inventory_number) {
                $this->validateInventoryNumber($record->site, $company, $validated['inventory_number']);
                $this->ensureInventoryNumberAvailable($validated['inventory_number'], $record->getKey());
            }

            $record->update($validated);
        });

        return response()->json([
            'message' => 'Inventory '.$this->label().' berhasil diperbarui.',
            'data' => $this->resource($record->fresh()),
        ]);
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        $record = $this->findAuthorized($request, $device);
        $record->delete();

        return response()->json(['message' => 'Inventory '.$this->label().' berhasil dihapus.']);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $rules = [
            'site' => ['nullable', 'string', 'max:25'],
            'company' => [$updating ? 'sometimes' : 'required', Rule::in(['PPA', 'AMM'])],
            'max_id' => ['prohibited'],
            'device_name' => [...$required, 'string', 'max:255'],
            'inventory_number' => [$updating ? 'sometimes' : 'required_without:company', 'string', 'max:255'],
            'asset_ho_number' => [...$required, 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'device_brand' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'note' => ['nullable', 'string'],
            'date_of_inventory' => ['nullable', 'date'],
        ];

        if ($this->hasFrequency()) {
            $rules['frequency'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    private function findAuthorized(Request $request, string $id): Model
    {
        $model = $this->modelClass();
        $device = $model::query()->findOrFail($id);
        $this->authorizedSite($request, $device->site);

        return $device;
    }

    private function generateNumber(string $site, ?string $company): string
    {
        if (! $company) {
            throw ValidationException::withMessages(['company' => ['Company wajib dipilih untuk membuat nomor inventory.']]);
        }

        $model = $this->modelClass();
        $prefix = $company.$this->inventorySiteCode($site).$this->deviceCode();
        $last = $model::withTrashed()->where('inventory_number', 'like', $prefix.'%')->orderByDesc('inventory_number')->value('inventory_number');
        preg_match('/(\d+)$/', (string) $last, $matches);

        return $prefix.str_pad(((int) ($matches[1] ?? 0)) + 1, 3, '0', STR_PAD_LEFT);
    }

    private function validateInventoryNumber(string $site, ?string $company, string $number): void
    {
        if ($company && ! str_starts_with(strtoupper($number), $company.$this->inventorySiteCode($site).$this->deviceCode())) {
            throw ValidationException::withMessages([
                'inventory_number' => ['Nomor inventory tidak sesuai dengan company, site, atau jenis perangkat.'],
            ]);
        }
    }

    private function ensureInventoryNumberAvailable(string $number, ?string $exceptId = null): void
    {
        $model = $this->modelClass();
        $exists = $model::withTrashed()
            ->where('inventory_number', $number)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['inventory_number' => ['Nomor inventory sudah digunakan.']]);
        }
    }

    private function inventorySiteCode(string $site): string
    {
        return match (strtoupper($site)) {
            'AMI' => 'LAC',
            default => strtoupper($site),
        };
    }

    private function inferCompany(?string $number): ?string
    {
        return match (true) {
            str_starts_with(strtoupper((string) $number), 'PPA') => 'PPA',
            str_starts_with(strtoupper((string) $number), 'AMM') => 'AMM',
            default => null,
        };
    }

    private function resource(Model $device): array
    {
        return [...$device->toArray(), 'company' => $this->inferCompany($device->inventory_number)];
    }
}
