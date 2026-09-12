<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ProcessesNetworkInspection;
use App\Models\InspeksiSwitch;

class SwitchInspectionController extends ProcessesNetworkInspection
{
    protected function inspectionModel(): string
    {
        return InspeksiSwitch::class;
    }

    protected function picaDeviceCode(): string
    {
        return 'SW';
    }
}
