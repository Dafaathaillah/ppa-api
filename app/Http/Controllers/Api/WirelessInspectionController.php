<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ProcessesNetworkInspection;
use App\Models\InspeksiWirelless;

class WirelessInspectionController extends ProcessesNetworkInspection
{
    protected function inspectionModel(): string
    {
        return InspeksiWirelless::class;
    }

    protected function picaDeviceCode(): string
    {
        return 'BB';
    }
}
