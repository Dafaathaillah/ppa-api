<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\InvPanelBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryPanelBoxController extends Controller
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

        $data = InvPanelBox::query()
            ->where('site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    foreach (['inventory_number', 'asset_ho_number', 'category_panel_box', 'panel_type', 'location'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (InvPanelBox $panelBox) => $this->resource($panelBox));

        return response()->json($data);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => self::STATUSES,
            'default_site' => strtoupper((string) $request->user()->site),
            'can_access_other_sites' => $this->canAccessOtherSites($request),
        ]]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $last = InvPanelBox::withTrashed()->where('site', $site)->max('max_id');
        $next = ((int) $last) + 1;

        return response()->json(['data' => [
            'inventory_number' => sprintf('%s/ICT/PBN/%03d', $site, $next),
            'max_id' => $next,
            'site' => $site,
        ]]);
    }

    public function show(Request $request, string $panelBox): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->findAuthorized($request, $panelBox))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        unset($validated['site'], $validated['wired_diagram']);
        $validated = $this->normalizeAliases($validated);
        $path = $request->hasFile('wired_diagram') ? $request->file('wired_diagram')->store('documents/panel-box', 'public') : null;

        try {
            $record = DB::transaction(function () use ($validated, $site, $path) {
                $this->ensureInventoryNumberAvailable($validated['inventory_number']);

                return InvPanelBox::create([
                    ...$validated,
                    'item_name' => $validated['item_name'] ?? 'PANEL BOX NETWORK',
                    'inspection_remark' => $validated['inspection_remark'] ?? ($validated['note'] ?? null),
                    'wired_diagram' => $path,
                    'site' => $site,
                ]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Inventory Panel Box berhasil dibuat.', 'data' => $this->resource($record)], 201);
    }

    public function update(Request $request, string $panelBox): JsonResponse
    {
        $record = $this->findAuthorized($request, $panelBox);
        $validated = $request->validate($this->rules(true));
        unset($validated['site'], $validated['inventory_number'], $validated['max_id'], $validated['wired_diagram']);
        $validated = $this->normalizeAliases($validated);
        $oldPath = $record->wired_diagram;
        $newPath = $request->hasFile('wired_diagram') ? $request->file('wired_diagram')->store('documents/panel-box', 'public') : null;

        try {
            DB::transaction(function () use ($record, $validated, $newPath) {
                $record->fill($validated);
                if ($newPath) {
                    $record->wired_diagram = $newPath;
                }
                $record->save();
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }

        if ($newPath && $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['message' => 'Inventory Panel Box berhasil diperbarui.', 'data' => $this->resource($record->fresh())]);
    }

    public function destroy(Request $request, string $panelBox): JsonResponse
    {
        abort_if($request->user()->role === 'ict_technician', 403, 'ICT Technician tidak dapat menghapus Panel Box.');
        $record = $this->findAuthorized($request, $panelBox);
        $path = $record->wired_diagram;
        $record->delete();
        if ($path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json(['message' => 'Inventory Panel Box berhasil dihapus.']);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'site' => ['nullable', 'string', 'max:25'],
            'max_id' => [...$required, 'integer', 'min:1'],
            'item_name' => ['nullable', 'string', 'max:255'],
            'inventory_number' => [...$required, 'string', 'max:255'],
            'asset_ho_number' => ['nullable', 'string', 'max:255'],
            'category_panel_box' => ['nullable', 'string', 'max:255'],
            'panel_box_category' => ['nullable', 'string', 'max:255'],
            'panel_type' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:255'],
            'inspection_remark' => ['nullable', 'string', 'max:255'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'wired_diagram' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ];
    }

    private function ensureInventoryNumberAvailable(string $number): void
    {
        if (InvPanelBox::withTrashed()->where('inventory_number', $number)->exists()) {
            throw ValidationException::withMessages(['inventory_number' => ['Nomor inventory sudah digunakan.']]);
        }
    }

    private function findAuthorized(Request $request, string $id): InvPanelBox
    {
        $record = InvPanelBox::query()->findOrFail($id);
        $this->authorizedSite($request, $record->site);

        return $record;
    }

    private function resource(InvPanelBox $record): array
    {
        return [
            ...$record->toArray(),
            'wired_diagram_url' => $record->wired_diagram ? Storage::disk('public')->url($record->wired_diagram) : null,
        ];
    }

    private function normalizeAliases(array $data): array
    {
        if (array_key_exists('panel_box_category', $data)) {
            $data['category_panel_box'] = $data['panel_box_category'];
            unset($data['panel_box_category']);
        }

        if (array_key_exists('remark', $data)) {
            $data['note'] = $data['remark'];
            $data['inspection_remark'] ??= $data['remark'];
            unset($data['remark']);
        }

        return $data;
    }
}
