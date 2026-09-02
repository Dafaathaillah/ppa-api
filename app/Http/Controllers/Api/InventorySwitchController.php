<?php

namespace App\Http\Controllers\Api;

use App\Models\InvSwitch;

class InventorySwitchController extends InventoryNetworkDeviceController
{
    protected function modelClass(): string
    {
        return InvSwitch::class;
    }

    protected function deviceCode(): string
    {
        return 'SW';
    }

    protected function label(): string
    {
        return 'Switch';
    }
}
