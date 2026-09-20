<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransactionService
{
    public function move(
        string $stockId,
        string $type,
        int $qty,
        User $actor,
        ?string $notes = null,
        ?string $referenceNo = null
    ): StockTransaction {
        return DB::transaction(function () use ($stockId, $type, $qty, $actor, $notes, $referenceNo): StockTransaction {
            $stock = Stock::query()->lockForUpdate()->findOrFail($stockId);

            return $this->applyMovement($stock, $type, $qty, $actor, $notes, $referenceNo);
        });
    }

    public function adjustMany(array $adjustments, User $actor): Collection
    {
        return DB::transaction(function () use ($adjustments, $actor): Collection {
            $ids = collect($adjustments)->pluck('stock_id');
            if ($ids->unique()->count() !== $ids->count()) {
                throw ValidationException::withMessages(['adjustments' => ['Stock yang sama tidak boleh muncul lebih dari sekali.']]);
            }

            $stocks = Stock::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($stocks->count() !== $ids->count()) {
                throw ValidationException::withMessages(['adjustments' => ['Salah satu stock tidak ditemukan.']]);
            }

            return collect($adjustments)->map(function (array $adjustment) use ($stocks, $actor) {
                $stock = $stocks->get($adjustment['stock_id']);
                $actualQty = (int) $adjustment['actual_qty'];

                if ($stock->current_qty === $actualQty) {
                    return null;
                }

                return $this->applyMovement($stock, 'ADJUSTMENT', $actualQty, $actor, $adjustment['notes'] ?? null);
            })->filter()->values();
        });
    }

    public function transfer(
        string $sourceId,
        string $destinationId,
        int $qty,
        User $actor,
        ?string $notes = null
    ): array {
        if ($sourceId === $destinationId) {
            throw ValidationException::withMessages(['destination_stock_id' => ['Stock tujuan harus berbeda dari stock sumber.']]);
        }

        return DB::transaction(function () use ($sourceId, $destinationId, $qty, $actor, $notes): array {
            $stocks = Stock::query()
                ->whereIn('id', [$sourceId, $destinationId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $stocks->get($sourceId);
            $destination = $stocks->get($destinationId);

            if (! $source || ! $destination) {
                throw ValidationException::withMessages(['destination_stock_id' => ['Stock sumber atau tujuan tidak ditemukan.']]);
            }
            if (! $source->is_active || ! $destination->is_active) {
                throw ValidationException::withMessages(['destination_stock_id' => ['Stock sumber dan tujuan harus aktif.']]);
            }
            if (strtoupper($source->uom) !== strtoupper($destination->uom)) {
                throw ValidationException::withMessages(['destination_stock_id' => ['UOM stock sumber dan tujuan harus sama.']]);
            }
            if ($qty > $source->current_qty) {
                throw ValidationException::withMessages(['qty' => ['Stock tidak mencukupi.']]);
            }

            $reference = $this->nextNumber($source->site, 'TRANSFER');
            $sourceBefore = $source->current_qty;
            $destinationBefore = $destination->current_qty;
            $source->update(['current_qty' => $sourceBefore - $qty]);
            $destination->update(['current_qty' => $destinationBefore + $qty]);

            $sourceTransaction = $this->createLedger($source, 'TRANSFER_OUT', $qty, $sourceBefore, $sourceBefore - $qty, $actor, $notes, $reference, $source->site, $destination->site);
            $destinationTransaction = $this->createLedger($destination, 'TRANSFER_IN', $qty, $destinationBefore, $destinationBefore + $qty, $actor, $notes, $reference, $source->site, $destination->site);

            return [
                'source_transaction' => $sourceTransaction,
                'destination_transaction' => $destinationTransaction,
                'source_stock' => $source->fresh()->load(['category', 'location']),
                'destination_stock' => $destination->fresh()->load(['category', 'location']),
            ];
        });
    }

    private function applyMovement(Stock $stock, string $type, int $qty, User $actor, ?string $notes, ?string $referenceNo = null): StockTransaction
    {
        if (! $stock->is_active) {
            throw ValidationException::withMessages(['stock_id' => ['Stock tidak aktif.']]);
        }

        $before = $stock->current_qty;
        $after = match ($type) {
            'STOCK_IN', 'RETURN' => $before + $qty,
            'STOCK_OUT' => $before - $qty,
            'ADJUSTMENT' => $qty,
            default => throw ValidationException::withMessages(['transaction_type' => ['Jenis transaksi tidak didukung.']]),
        };

        if ($after < 0) {
            throw ValidationException::withMessages(['qty' => ['Stock tidak mencukupi.']]);
        }

        $stock->update(['current_qty' => $after]);
        $number = $this->nextNumber($stock->site, $type);

        return $this->createLedger(
            $stock,
            $type,
            $type === 'ADJUSTMENT' ? abs($after - $before) : $qty,
            $before,
            $after,
            $actor,
            $notes,
            $referenceNo ?? $number
        );
    }

    private function createLedger(
        Stock $stock,
        string $type,
        int $qty,
        int $before,
        int $after,
        User $actor,
        ?string $notes,
        string $referenceNo,
        ?string $fromSite = null,
        ?string $toSite = null
    ): StockTransaction {
        return StockTransaction::create([
            'stock_id' => $stock->id,
            'site' => $stock->site,
            'transaction_no' => $referenceNo,
            'transaction_type' => $type,
            'qty' => $qty,
            'stock_before' => $before,
            'stock_after' => $after,
            'reference_no' => $referenceNo,
            'from_site' => $fromSite,
            'to_site' => $toSite,
            'notes' => $notes,
            'created_by' => $actor->name,
        ]);
    }

    private function nextNumber(string $site, string $type): string
    {
        $prefix = match ($type) {
            'STOCK_IN' => 'STK-IN',
            'STOCK_OUT' => 'STK-OUT',
            'RETURN' => 'RTN',
            'ADJUSTMENT' => 'ADJ',
            default => 'TRF',
        };
        $month = now()->format('m');
        $year = now()->format('Y');
        $numbers = StockTransaction::query()
            ->where('site', $site)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->lockForUpdate()
            ->pluck('transaction_no');
        $highest = $numbers->reduce(function (int $carry, ?string $number): int {
            preg_match('#/([0-9]+)/[0-9]{2}/[0-9]{4}$#', (string) $number, $matches);

            return max($carry, (int) ($matches[1] ?? 0));
        }, 0);

        return sprintf('%s/%s/%03d/%s/%s', $prefix, strtoupper($site), $highest + 1, $month, $year);
    }
}
