<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\CategoryTower;
use App\Models\InvTower;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryTowerController extends Controller
{
    use AuthorizesInventorySite;

    private const STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category_id' => ['nullable', 'uuid', Rule::exists('category_tower', 'id')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $towers = InvTower::query()
            ->with('category:id,category_code,category_name,is_active')
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('tower_code', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhere('detail_location', 'like', "%{$search}%")
                        ->orWhere('type_tower', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($category) => $category->where('category_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($towers);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => self::STATUSES,
                'categories' => CategoryTower::query()
                    ->where('is_active', true)
                    ->orderBy('category_code')
                    ->get(['id', 'category_code', 'category_name']),
                'default_site' => strtoupper((string) $request->user()->site),
                'can_access_other_sites' => $this->canAccessOtherSites($request),
            ],
        ]);
    }

    public function show(Request $request, string $tower): JsonResponse
    {
        return response()->json([
            'data' => $this->findAuthorized($request, $tower)->load('category:id,category_code,category_name,is_active'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        unset($validated['site']);

        $tower = DB::transaction(function () use ($validated, $site) {
            $this->ensureTowerCodeAvailable($site, $validated['tower_code']);

            return InvTower::create([...$validated, 'site' => $site]);
        });

        return response()->json([
            'message' => 'Inventory Tower berhasil dibuat.',
            'data' => $tower->load('category:id,category_code,category_name,is_active'),
        ], 201);
    }

    public function update(Request $request, string $tower): JsonResponse
    {
        $record = $this->findAuthorized($request, $tower);
        $validated = $request->validate($this->rules(true));
        unset($validated['site'], $validated['tower_code']);

        DB::transaction(fn () => $record->update($validated));

        return response()->json([
            'message' => 'Inventory Tower berhasil diperbarui.',
            'data' => $record->fresh()->load('category:id,category_code,category_name,is_active'),
        ]);
    }

    public function destroy(Request $request, string $tower): JsonResponse
    {
        $record = $this->findAuthorized($request, $tower);
        $record->delete();

        return response()->json(['message' => 'Inventory Tower berhasil dihapus.']);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'site' => ['nullable', 'string', 'max:25'],
            'tower_code' => [...$required, 'string', 'max:255'],
            'category_id' => ['nullable', 'uuid', Rule::exists('category_tower', 'id')->where('is_active', true)],
            'location' => [...$required, 'string', 'max:255'],
            'detail_location' => [...$required, 'string', 'max:255'],
            'type_tower' => [...$required, 'string', 'max:255'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'note' => [...$required, 'string', 'max:255'],
            'remark_inspeksi' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function findAuthorized(Request $request, string $id): InvTower
    {
        $tower = InvTower::query()->findOrFail($id);
        $this->authorizedSite($request, $tower->site);

        return $tower;
    }

    private function ensureTowerCodeAvailable(string $site, string $towerCode): void
    {
        if (InvTower::withTrashed()->where('site', $site)->where('tower_code', trim($towerCode))->exists()) {
            throw ValidationException::withMessages([
                'tower_code' => ['Kode Tower sudah digunakan pada site tersebut.'],
            ]);
        }
    }
}
