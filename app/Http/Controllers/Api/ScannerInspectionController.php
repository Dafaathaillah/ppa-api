<?php

namespace App\Http\Controllers\Api;

use App\Models\InspeksiScanner;
use Illuminate\Database\Eloquent\Model;

class ScannerInspectionController extends AbstractSimpleAssetInspectionController
{
    protected function modelClass(): string
    {
        return InspeksiScanner::class;
    }

    protected function relation(): string
    {
        return 'Scanner';
    }

    protected function deviceType(): string
    {
        return 'scanner';
    }

    protected function picaCode(): string
    {
        return 'SCN';
    }

    protected function remarkField(): string
    {
        return 'remark';
    }

    protected function checklistFields(): array
    {
        return ['fisik', 'kaca', 'kabel', 'power', 'mekanik', 'hasil_scan'];
    }

    protected function inventoryResource(Model $inventory): array
    {
        return ['id' => $inventory->getKey(), 'scanner_code' => $inventory->scanner_code, 'item_name' => $inventory->item_name,
            'location' => $inventory->location, 'status' => $inventory->status, 'site' => $inventory->site];
    }
}
