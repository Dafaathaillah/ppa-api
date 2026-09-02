<?php

namespace App\Http\Controllers\Api;

use App\Models\InvAp;

class InventoryAccessPointController extends InventoryNetworkDeviceController
{
    protected function modelClass(): string
    {
        return InvAp::class;
    }

    protected function deviceCode(): string
    {
        return 'AP';
    }

    protected function label(): string
    {
        return 'Access Point';
    }

    protected function hasFrequency(): bool
    {
        return true;
    }
}
