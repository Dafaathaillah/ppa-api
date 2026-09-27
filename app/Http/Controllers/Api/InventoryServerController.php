<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\InvServer;
use App\Models\ServerCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryServerController extends Controller
{
    use AuthorizesInventorySite;

    private const COMPANIES = ['PPA', 'AMM'];

    private const STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'];

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => self::STATUSES,
            'companies' => self::COMPANIES,
            'categories' => ServerCategory::query()
                ->where('is_active', true)
                ->orderBy('category_name')
                ->get(['id', 'category_name'])
                ->map(fn (ServerCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->category_name,
                ])
                ->values(),
            'default_site' => strtoupper((string) $request->user()->site),
            'can_access_other_sites' => $this->canAccessOtherSites($request),
        ]]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'company' => ['required', Rule::in(self::COMPANIES)],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'server_code' => $this->generateNumber($site, $validated['company']),
            'site' => $site,
            'company' => $validated['company'],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $servers = InvServer::query()
            ->with('serverCategory')
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['category_id'] ?? null, fn (Builder $query, string $category) => $query->where('category_id', $category))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    foreach ($this->searchableColumns() as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($servers);
    }

    public function show(Request $request, string $server): JsonResponse
    {
        return response()->json(['data' => $this->findAuthorized($request, $server)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        unset($validated['site']);

        $server = DB::transaction(function () use ($validated, $site): InvServer {
            $this->validateServerCode($validated['server_code'], $site, $validated['company']);
            $this->ensureServerCodeAvailable($validated['server_code']);

            $maxId = ((int) InvServer::withTrashed()->lockForUpdate()->max('max_id')) + 1;

            return InvServer::create([
                ...$validated,
                'max_id' => $maxId,
                'site' => $site,
            ]);
        });

        return response()->json([
            'message' => 'Inventory Server berhasil dibuat.',
            'data' => $server->load('serverCategory'),
        ], 201);
    }

    public function update(Request $request, string $server): JsonResponse
    {
        $record = $this->findAuthorized($request, $server);
        $validated = $request->validate($this->rules(true));
        unset($validated['site']);

        DB::transaction(function () use ($record, $validated): void {
            $company = $validated['company'] ?? $record->company ?? substr($record->server_code, 0, 3);
            $serverCode = $validated['server_code'] ?? $record->server_code;

            $this->validateServerCode($serverCode, $record->site, $company);
            $this->ensureServerCodeAvailable($serverCode, $record->getKey());
            $record->update($validated);
        });

        return response()->json([
            'message' => 'Inventory Server berhasil diperbarui.',
            'data' => $record->fresh()->load('serverCategory'),
        ]);
    }

    public function destroy(Request $request, string $server): JsonResponse
    {
        $record = $this->findAuthorized($request, $server);
        $record->delete();

        return response()->json(['message' => 'Inventory Server berhasil dihapus.']);
    }

    private function filterRules(): array
    {
        return [
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category_id' => ['nullable', 'uuid', 'exists:server_categories,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'site' => ['nullable', 'string', 'max:25'],
            'company' => [...$required, Rule::in(self::COMPANIES)],
            'server_code' => [...$required, 'string', 'max:255'],
            'asset_ho_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'server_name' => [...$required, 'string', 'max:255'],
            'server_brand' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                ...$required,
                'uuid',
                Rule::exists('server_categories', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'server_type' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'date_of_inventory' => ['nullable', 'date'],
            'date_of_deploy' => ['nullable', 'date'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'service_tag' => ['nullable', 'string', 'max:255'],
            'cpu' => ['nullable', 'string', 'max:255'],
            'ram' => ['nullable', 'string', 'max:255'],
            'hdd' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'inspection_remark' => ['nullable', 'string'],
        ];
    }

    private function searchableColumns(): array
    {
        return [
            'server_code', 'asset_ho_number', 'serial_number', 'ip_address',
            'server_name', 'server_brand', 'server_type', 'location',
            'service_tag', 'cpu', 'ram', 'hdd', 'os',
        ];
    }

    private function generateNumber(string $site, string $company): string
    {
        $prefix = strtoupper($company.$site.'SRV');
        $last = InvServer::withTrashed()
            ->where('server_code', 'like', $prefix.'%')
            ->orderByDesc('server_code')
            ->value('server_code');
        preg_match('/(\d+)$/', (string) $last, $matches);

        return $prefix.str_pad(((int) ($matches[1] ?? 0)) + 1, 3, '0', STR_PAD_LEFT);
    }

    private function validateServerCode(string $serverCode, string $site, string $company): void
    {
        $prefix = strtoupper($company.$site.'SRV');

        if (! str_starts_with(strtoupper($serverCode), $prefix)) {
            throw ValidationException::withMessages([
                'server_code' => ['Nomor inventory tidak sesuai dengan company, site, atau jenis perangkat.'],
            ]);
        }
    }

    private function ensureServerCodeAvailable(string $serverCode, ?string $exceptId = null): void
    {
        $exists = InvServer::withTrashed()
            ->where('server_code', $serverCode)
            ->when($exceptId, fn (Builder $query, string $id) => $query->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'server_code' => ['Nomor inventory sudah digunakan.'],
            ]);
        }
    }

    private function findAuthorized(Request $request, string $id): InvServer
    {
        $server = InvServer::query()->with('serverCategory')->findOrFail($id);
        $this->authorizedSite($request, $server->site);

        return $server;
    }
}
