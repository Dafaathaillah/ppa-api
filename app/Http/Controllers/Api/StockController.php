<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockCategory;
use App\Models\StockLocation;
use App\Services\StockTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    use AuthorizesInventorySite;

    public function __construct(private readonly StockTransactionService $transactions) {}

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'categories' => StockCategory::query()->where('is_active', true)->orderBy('category_name')->get(['id', 'category_code', 'category_name']),
            'locations' => StockLocation::query()->where('site', $site)->where('is_active', true)->orderBy('location_name')->get(['id', 'site', 'location_code', 'location_name']),
            'transaction_types' => ['STOCK_IN', 'STOCK_OUT', 'RETURN', 'ADJUSTMENT', 'TRANSFER'],
            'uoms' => ['PCS', 'UNIT', 'BOX', 'METER', 'ROLL', 'SET'],
            'default_site' => strtoupper((string) $request->user()->site),
            'selected_site' => $site,
            'can_access_other_sites' => $this->canAccessOtherSites($request),
        ]]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'stock_category_id' => ['required', 'integer', Rule::exists('stock_categories', 'id')->where('is_active', true)],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'item_code' => $this->generateItemCode((int) $validated['stock_category_id']),
            'site' => $site,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $stocks = Stock::query()
            ->with(['category', 'location'])
            ->where('site', $site)
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
            ->when($validated['category_id'] ?? null, fn ($query, $id) => $query->where('stock_category_id', $id))
            ->when($validated['location_id'] ?? null, fn ($query, $id) => $query->where('stock_location_id', $id))
            ->when($validated['low_stock'] ?? false, fn ($query) => $query->whereColumn('current_qty', '<=', 'min_qty'))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('item_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($stocks);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request));
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $openingQty = (int) ($validated['current_qty'] ?? 0);
        unset($validated['site'], $validated['current_qty']);

        $stock = DB::transaction(function () use ($validated, $site, $openingQty, $request): Stock {
            $validated['item_code'] = $this->generateItemCode((int) $validated['stock_category_id'], true);
            $stock = Stock::create([...$validated, 'site' => $site, 'current_qty' => 0]);
            if ($openingQty > 0) {
                $this->transactions->move($stock->id, 'STOCK_IN', $openingQty, $request->user(), 'Opening balance');
            }

            return $stock->fresh()->load(['category', 'location']);
        });

        return response()->json(['message' => 'Stock berhasil dibuat.', 'data' => $stock], 201);
    }

    public function show(Request $request, string $stock): JsonResponse
    {
        return response()->json(['data' => $this->findAuthorized($request, $stock)->load(['category', 'location'])]);
    }

    public function update(Request $request, string $stock): JsonResponse
    {
        $record = $this->findAuthorized($request, $stock);
        $validated = $request->validate($this->rules($request, true, $record));
        unset($validated['site'], $validated['current_qty'], $validated['item_code']);
        $record->update($validated);

        return response()->json(['message' => 'Stock berhasil diperbarui.', 'data' => $record->fresh()->load(['category', 'location'])]);
    }

    public function destroy(Request $request, string $stock): JsonResponse
    {
        $record = $this->findAuthorized($request, $stock);
        if ($record->transactions()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['stock' => ['Item sudah memiliki transaksi dan tidak dapat dihapus.']]);
        }
        $record->delete();

        return response()->json(['message' => 'Stock berhasil dihapus.']);
    }

    private function rules(Request $request, bool $updating = false, ?Stock $stock = null): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $site = strtoupper((string) ($request->input('site') ?: $request->user()->site));

        return [
            'site' => [$updating ? 'prohibited' : 'nullable', 'string', 'max:25'],
            'item_code' => ['prohibited'],
            'item_name' => [...$required, 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'stock_category_id' => [$updating ? 'sometimes' : 'required', 'integer', Rule::exists('stock_categories', 'id')->where('is_active', true)],
            'stock_location_id' => [$updating ? 'sometimes' : 'required', 'integer', Rule::exists('stock_locations', 'id')->where(fn ($query) => $query->where('site', $stock?->site ?? $site)->where('is_active', true))],
            'brand' => ['nullable', 'string', 'max:255'],
            'current_qty' => $updating ? ['prohibited'] : ['nullable', 'integer', 'min:0'],
            'min_qty' => [$updating ? 'sometimes' : 'required', 'integer', 'min:0'],
            'uom' => [...$required, 'string', 'max:25'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function findAuthorized(Request $request, string $id): Stock
    {
        $stock = Stock::query()->findOrFail($id);
        $this->authorizedSite($request, $stock->site);

        return $stock;
    }

    private function generateItemCode(int $categoryId, bool $lock = false): string
    {
        $category = StockCategory::query()->where('is_active', true)->findOrFail($categoryId);
        $query = Stock::withTrashed()->where('stock_category_id', $categoryId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $highest = $query->pluck('item_code')->reduce(function (int $carry, string $code): int {
            preg_match('/([0-9]+)$/', $code, $matches);

            return max($carry, (int) ($matches[1] ?? 0));
        }, 0);

        return $category->category_code.'-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
