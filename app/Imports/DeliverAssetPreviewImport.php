<?php

namespace App\Imports;

use App\Models\DeliverAsset;
use App\Models\InvLaptop;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DeliverAssetPreviewImport implements ToCollection, WithHeadingRow
{
    public array $rows = [];

    public function collection(Collection $rows): void
    {
        $sites = ['HO', 'BA', 'MIFA', 'MHU', 'ADW', 'AMI', 'PIK', 'BGE', 'BIB', 'IPT', 'MLP', 'MIP', 'VIB', 'SBS', 'SKS', 'MAC', 'DMP'];

        foreach ($rows as $row) {
            $inventory = trim((string) ($row['inventory_number'] ?? ''));
            $destination = strtoupper(trim((string) ($row['destination_site'] ?? '')));
            $note = trim((string) ($row['note'] ?? ''));
            $result = compact('inventory', 'destination', 'note');

            if ($destination === '') {
                $this->append($result, 'Destination site is required.');

                continue;
            }
            if (! in_array($destination, $sites, true)) {
                $this->append($result, 'Destination site is invalid. Allowed sites: '.implode(', ', $sites));

                continue;
            }
            if ($inventory === '') {
                $this->append($result, 'Inventory number is required.');

                continue;
            }

            $laptop = InvLaptop::where('laptop_code', $inventory)->first();
            if (! $laptop) {
                $this->append($result, 'Inventory not found');

                continue;
            }
            if (DeliverAsset::where('asset_id', $laptop->id)->where('status', 'WAITING_RECEIVE')->exists()) {
                $this->append($result, 'Already waiting delivery');

                continue;
            }

            $this->rows[] = [
                'inventory_number' => $inventory,
                'destination_site' => $destination,
                'note' => $note,
                'asset_id' => $laptop->id,
                'status' => 'READY',
                'message' => 'Ready Import',
            ];
        }
    }

    private function append(array $row, string $message): void
    {
        $this->rows[] = [
            'inventory_number' => $row['inventory'],
            'destination_site' => $row['destination'],
            'note' => $row['note'],
            'status' => 'ERROR',
            'message' => $message,
        ];
    }
}
