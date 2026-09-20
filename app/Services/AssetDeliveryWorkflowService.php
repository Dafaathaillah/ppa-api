<?php

namespace App\Services;

use App\Models\DeliverAsset;
use App\Models\InvLaptop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetDeliveryWorkflowService
{
    public function create(string $assetId, string $destination, ?string $note, ?string $pdfUrl, User $actor): DeliverAsset
    {
        return DB::transaction(function () use ($assetId, $destination, $note, $pdfUrl, $actor): DeliverAsset {
            $laptop = InvLaptop::query()->lockForUpdate()->findOrFail($assetId);
            if (DeliverAsset::query()->where('asset_id', $assetId)->where('status', 'WAITING_RECEIVE')->exists()) {
                throw ValidationException::withMessages(['asset_id' => ['Laptop masih dalam proses delivery.']]);
            }
            if ($laptop->dept !== 'STK' || ! in_array($laptop->status, ['READY_STANDBY', 'READY_USED', 'READY_STANBY'], true)) {
                throw ValidationException::withMessages(['asset_id' => ['Laptop tidak memenuhi syarat delivery.']]);
            }

            $delivery = DeliverAsset::create([
                'asset_id' => $laptop->id,
                'device_category' => 'LAPTOP',
                'departure_site' => strtoupper($laptop->site),
                'arrived_site' => strtoupper($destination),
                'delivery_by' => $actor->name,
                'status' => 'WAITING_RECEIVE',
                'reject_reason' => '-',
                'delivery_date' => now(),
                'note' => $note,
                'link_ba' => $pdfUrl,
            ]);
            $laptop->update(['status' => 'IN_TRANSIT']);

            return $delivery->fresh();
        });
    }

    public function receive(DeliverAsset $delivery, array $data, User $actor): DeliverAsset
    {
        return DB::transaction(function () use ($delivery, $data, $actor): DeliverAsset {
            $delivery = DeliverAsset::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->ensureWaiting($delivery);
            if ($data['asset_id'] !== $delivery->asset_id) {
                throw ValidationException::withMessages(['asset_id' => ['Asset tidak sesuai data delivery.']]);
            }
            $laptop = InvLaptop::query()->lockForUpdate()->findOrFail($delivery->asset_id);
            $lastNumber = (int) substr($data['inventory_number'], strrpos($data['inventory_number'], '-') + 1);
            $laptop->update([
                'site' => $delivery->arrived_site,
                'laptop_code' => $data['inventory_number'],
                'max_id' => $lastNumber,
                'number_asset_ho' => $data['asset_ho_number'] ?? null,
                'user_alls_id' => $data['user_id'],
                'license' => $data['license'] ?? null,
                'date_of_deploy' => $data['deploy_date'] ?? null,
                'status' => 'READY_USED',
            ]);
            $delivery->update([
                'received_by' => $actor->name,
                'received_date' => now(),
                'status' => 'RECEIVED',
                'reject_reason' => '-',
                'note' => $data['note'] ?? null,
            ]);

            return $delivery->fresh();
        });
    }

    public function reject(DeliverAsset $delivery, ?string $reason, User $actor): DeliverAsset
    {
        return DB::transaction(function () use ($delivery, $reason, $actor): DeliverAsset {
            $delivery = DeliverAsset::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->ensureWaiting($delivery);
            $delivery->update([
                'status' => 'REJECTED',
                'rejected_by' => $actor->name,
                'rejected_at' => now(),
                'reject_reason' => $reason,
            ]);

            return $delivery->fresh();
        });
    }

    private function ensureWaiting(DeliverAsset $delivery): void
    {
        if ($delivery->status !== 'WAITING_RECEIVE') {
            throw ValidationException::withMessages(['delivery' => ['Delivery has already been processed.']]);
        }
    }
}
