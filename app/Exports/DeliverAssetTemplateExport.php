<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DeliverAssetTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['Inventory Number', 'Destination Site', 'Note'];
    }

    public function array(): array
    {
        return [
            ['HO-NB-STK-001', 'BIB', 'New Employee'],
            ['HO-NB-STK-002', 'BA', 'Replacement'],
        ];
    }
}
