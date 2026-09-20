<?php

namespace App\Services;

use App\Models\Department;
use App\Models\InspeksiComputer;
use App\Models\InspeksiLaptop;
use App\Models\InvComputer;
use App\Models\InvLaptop;
use App\Models\MutasiAsset;
use App\Models\PerangkatBreakdown;
use App\Models\User;
use App\Models\UserAll;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetMutationWorkflowService
{
    public function accept(MutasiAsset $mutation, string $category, Department $department, string $generatedCode, User $actor): MutasiAsset
    {
        return DB::transaction(function () use ($mutation, $category, $department, $generatedCode, $actor): MutasiAsset {
            $mutation = MutasiAsset::query()->lockForUpdate()->findOrFail($mutation->id);
            $this->ensurePending($mutation);

            if ($category !== $mutation->device_category) {
                throw ValidationException::withMessages(['category_asset' => ['Kategori asset tidak sesuai dengan data mutasi.']]);
            }

            $inventory = $category === 'LAPTOP'
                ? InvLaptop::query()->lockForUpdate()->findOrFail($mutation->asset_id)
                : InvComputer::query()->lockForUpdate()->findOrFail($mutation->asset_id);
            $this->validateCode($mutation, $department, $generatedCode, $inventory->id);

            $mutation->update([
                'approval_status' => 'APPROVED',
                'accepted_by' => $actor->name,
                'accepted_at' => now(),
            ]);
            UserAll::query()->where('nrp', $mutation->pic_nrp)->update(['site' => $mutation->arrived_site]);

            if ($category === 'LAPTOP') {
                $inventory->update(['site' => $mutation->arrived_site, 'dept' => $department->code, 'laptop_code' => $generatedCode]);
                InspeksiLaptop::query()->where('inv_laptop_id', $inventory->id)->update(['site' => $mutation->arrived_site]);
            } else {
                $inventory->update(['site' => $mutation->arrived_site, 'dept' => $department->code, 'computer_code' => $generatedCode]);
                InspeksiComputer::query()->where('inv_computer_id', $inventory->id)->update(['site' => $mutation->arrived_site]);
            }

            PerangkatBreakdown::query()->where('id_perangkat', $inventory->id)->update([
                'inventory_number' => $generatedCode,
                'location' => $mutation->arrived_site,
            ]);

            return $mutation->fresh();
        });
    }

    public function reject(MutasiAsset $mutation, string $reason, User $actor): MutasiAsset
    {
        return DB::transaction(function () use ($mutation, $reason, $actor): MutasiAsset {
            $mutation = MutasiAsset::query()->lockForUpdate()->findOrFail($mutation->id);
            $this->ensurePending($mutation);
            $mutation->update([
                'approval_status' => 'REJECT',
                'reject_reason' => $reason,
                'rejected_by' => $actor->name,
                'rejected_at' => now(),
            ]);

            return $mutation->fresh();
        });
    }

    private function ensurePending(MutasiAsset $mutation): void
    {
        if (! $mutation->canBeProcessed()) {
            throw ValidationException::withMessages(['mutation' => ['Mutasi asset ini sudah selesai diproses.']]);
        }
    }

    private function validateCode(MutasiAsset $mutation, Department $department, string $code, string $assetId): void
    {
        $sitePrefix = strtoupper($mutation->arrived_site) === 'AMI' ? 'LAC' : strtoupper($mutation->arrived_site);
        $devicePrefix = $mutation->device_category === 'LAPTOP' ? 'NB' : 'PC';
        $prefix = $sitePrefix.'-'.$devicePrefix.'-'.strtoupper($department->code).'-';
        if (! preg_match('/^'.preg_quote($prefix, '/').'[0-9]{3}$/', strtoupper($code))) {
            throw ValidationException::withMessages(['generated_code' => ['Kode inventory tidak sesuai site, kategori, atau department tujuan.']]);
        }

        $query = $mutation->device_category === 'LAPTOP'
            ? InvLaptop::withTrashed()->where('laptop_code', $code)
            : InvComputer::withTrashed()->where('computer_code', $code);
        if ($query->where('id', '!=', $assetId)->exists()) {
            throw ValidationException::withMessages(['generated_code' => ['Kode inventory sudah digunakan.']]);
        }
    }
}
