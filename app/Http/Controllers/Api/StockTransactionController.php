<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockTransaction;
use App\Services\StockTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockTransactionController extends Controller
{
    use AuthorizesInventorySite;

    public function __construct(private readonly StockTransactionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'stock_id' => ['nullable', 'uuid'],
            'transaction_type' => ['nullable', Rule::in(StockTransaction::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        $data = StockTransaction::query()
            ->with(['stock.category', 'stock.location'])
            ->where('site', $site)
            ->when($validated['stock_id'] ?? null, fn ($query, $id) => $query->where('stock_id', $id))
            ->when($validated['transaction_type'] ?? null, fn ($query, $type) => $query->where('transaction_type', $type))
            ->when($validated['start_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['end_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('transaction_no', 'like', "%{$search}%")
                        ->orWhere('reference_no', 'like', "%{$search}%")
                        ->orWhereHas('stock', fn ($stock) => $stock->where('item_code', 'like', "%{$search}%")->orWhere('item_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($data);
    }

    public function show(Request $request, string $transaction): JsonResponse
    {
        $record = StockTransaction::query()->findOrFail($transaction);
        $this->authorizedSite($request, $record->site);

        return response()->json(['data' => $record->load(['stock.category', 'stock.location'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'uuid', 'exists:stocks,id'],
            'transaction_type' => ['required', Rule::in(['STOCK_IN', 'STOCK_OUT', 'RETURN', 'ADJUSTMENT'])],
            'qty' => ['required', 'integer', Rule::when($request->input('transaction_type') === 'ADJUSTMENT', ['min:0'], ['min:1'])],
            'notes' => ['nullable', 'string'],
            'transaction_no' => ['prohibited'],
            'reference_no' => ['prohibited'],
            'stock_before' => ['prohibited'],
            'stock_after' => ['prohibited'],
            'site' => ['prohibited'],
            'created_by' => ['prohibited'],
        ]);
        $stock = Stock::query()->findOrFail($validated['stock_id']);
        $this->authorizedSite($request, $stock->site);
        $transaction = $this->service->move($stock->id, $validated['transaction_type'], (int) $validated['qty'], $request->user(), $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Transaksi stock berhasil disimpan.',
            'data' => ['transaction' => $transaction, 'stock' => $stock->fresh()->load(['category', 'location'])],
        ], 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'uuid', 'exists:stocks,id'],
            'destination_stock_id' => ['required', 'uuid', 'different:stock_id', 'exists:stocks,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);
        $source = Stock::query()->findOrFail($validated['stock_id']);
        $destination = Stock::query()->findOrFail($validated['destination_stock_id']);
        $this->authorizedSite($request, $source->site);
        $this->authorizedSite($request, $destination->site);
        $result = $this->service->transfer($source->id, $destination->id, (int) $validated['qty'], $request->user(), $validated['notes'] ?? null);

        return response()->json(['message' => 'Transfer stock berhasil.', 'data' => $result], 201);
    }

    public function bulkAdjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'adjustments' => ['required', 'array', 'min:1'],
            'adjustments.*.stock_id' => ['required', 'uuid', 'distinct', 'exists:stocks,id'],
            'adjustments.*.actual_qty' => ['required', 'integer', 'min:0'],
            'adjustments.*.notes' => ['nullable', 'string'],
        ]);
        $stocks = Stock::query()->whereIn('id', collect($validated['adjustments'])->pluck('stock_id'))->get();
        foreach ($stocks as $stock) {
            $this->authorizedSite($request, $stock->site);
        }
        $transactions = $this->service->adjustMany($validated['adjustments'], $request->user());

        return response()->json(['message' => 'Stock opname berhasil disimpan.', 'data' => ['transactions' => $transactions]]);
    }
}
