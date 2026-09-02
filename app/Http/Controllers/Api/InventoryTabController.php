<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\TabOva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryTabController extends Controller
{
    use AuthorizesInventorySite;

    private const STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $tabs = TabOva::query()
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    foreach (['cn_unit', 'sn_tab', 'merk_tab', 'type_tab', 'no_asset_tab', 'nomor_sim', 'pic_tab', 'lokasi_tab'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (TabOva $tab) => $this->resource($tab));

        return response()->json($tabs);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => self::STATUSES,
                'default_site' => strtoupper((string) $request->user()->site),
                'can_access_other_sites' => $this->canAccessOtherSites($request),
            ],
        ]);
    }

    public function show(Request $request, string $tab): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->findAuthorized($request, $tab))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        unset($validated['site'], $validated['link_foto']);
        $this->ensureAssetNumberAvailable($request->input('no_asset_tab'));

        $imagePath = $request->hasFile('link_foto')
            ? $request->file('link_foto')->store('images/tab-ova', 'public')
            : null;

        try {
            $tab = DB::transaction(fn () => TabOva::create([
                ...$validated,
                'link_foto' => $imagePath,
                'site' => $site,
                'created_by' => $request->user()->name,
            ]));
        } catch (\Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Inventory TAB berhasil dibuat.',
            'data' => $this->resource($tab),
        ], 201);
    }

    public function update(Request $request, string $tab): JsonResponse
    {
        $record = $this->findAuthorized($request, $tab);
        $validated = $request->validate($this->rules(true));
        unset($validated['site'], $validated['link_foto']);

        if ($request->has('no_asset_tab')) {
            $this->ensureAssetNumberAvailable($request->input('no_asset_tab'), $record->getKey());
        }

        $oldImage = $record->link_foto;
        $newImage = $request->hasFile('link_foto')
            ? $request->file('link_foto')->store('images/tab-ova', 'public')
            : null;

        try {
            DB::transaction(function () use ($record, $validated, $newImage) {
                $record->fill($validated);
                if ($newImage) {
                    $record->link_foto = $newImage;
                }
                $record->save();
            });
        } catch (\Throwable $exception) {
            if ($newImage) {
                Storage::disk('public')->delete($newImage);
            }
            throw $exception;
        }

        if ($newImage && $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

        return response()->json([
            'message' => 'Inventory TAB berhasil diperbarui.',
            'data' => $this->resource($record->fresh()),
        ]);
    }

    public function destroy(Request $request, string $tab): JsonResponse
    {
        $record = $this->findAuthorized($request, $tab);
        $image = $record->link_foto;
        $record->delete();
        if ($image) {
            Storage::disk('public')->delete($image);
        }

        return response()->json(['message' => 'Inventory TAB berhasil dihapus.']);
    }

    private function rules(bool $updating = false): array
    {
        return [
            'site' => ['nullable', 'string', 'max:25'],
            'unit_id' => ['nullable', 'uuid'],
            'max_id' => ['nullable', 'integer', 'min:0'],
            'cn_unit' => ['nullable', 'string', 'max:255'],
            'sn_tab' => ['nullable', 'string', 'max:255'],
            'merk_tab' => ['nullable', 'string', 'max:255'],
            'type_tab' => ['nullable', 'string', 'max:255'],
            'no_asset_tab' => ['nullable', 'string', 'max:255'],
            'nomor_sim' => ['nullable', 'string', 'max:255'],
            'pic_tab' => ['nullable', 'string', 'max:255'],
            'lokasi_tab' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'status' => [$updating ? 'sometimes' : 'required', Rule::in(self::STATUSES)],
            'link_foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    private function findAuthorized(Request $request, string $id): TabOva
    {
        $tab = TabOva::query()->findOrFail($id);
        $this->authorizedSite($request, $tab->site);

        return $tab;
    }

    private function ensureAssetNumberAvailable(?string $assetNumber, ?string $exceptId = null): void
    {
        $assetNumber = trim((string) $assetNumber);
        if ($assetNumber === '' || $assetNumber === '-') {
            return;
        }

        $existing = TabOva::withTrashed()
            ->where('no_asset_tab', $assetNumber)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'no_asset_tab' => ["No Asset {$assetNumber} sudah digunakan oleh site {$existing->site}."],
            ]);
        }
    }

    private function resource(TabOva $tab): array
    {
        return [
            ...$tab->only(['id', 'unit_id', 'max_id', 'cn_unit', 'sn_tab', 'merk_tab', 'type_tab', 'no_asset_tab', 'nomor_sim', 'pic_tab', 'lokasi_tab', 'remark', 'site', 'status', 'created_by']),
            'link_foto' => $tab->link_foto,
            'foto_url' => $tab->link_foto ? Storage::disk('public')->url($tab->link_foto) : null,
            'created_at' => $tab->created_at,
            'updated_at' => $tab->updated_at,
        ];
    }
}
