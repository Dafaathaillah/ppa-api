<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ProcessesNetworkInspection;
use App\Models\InspeksiAccessPoint;

class AccessPointInspectionController extends ProcessesNetworkInspection
{
    protected function inspectionModel(): string
    {
        return InspeksiAccessPoint::class;
    }

    protected function picaDeviceCode(): string
    {
        return 'AP';
    }
}
