<?php

namespace App\Http\Controllers\Api;

use App\Models\InvWirelless;

class InventoryWirelessController extends InventoryNetworkDeviceController
{
    protected function modelClass(): string
    {
        return InvWirelless::class;
    }

    protected function deviceCode(): string
    {
        return 'BB';
    }

    protected function label(): string
    {
        return 'Wireless';
    }

    protected function hasFrequency(): bool
    {
        return true;
    }
}
