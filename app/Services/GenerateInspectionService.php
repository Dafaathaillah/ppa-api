<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class GenerateInspectionService
{
    private const TYPES = [
        'access-point' => [\App\Models\InvAp::class, \App\Models\InspeksiAccessPoint::class, 'inv_ap_id', 'month', 'year', 'inspection_status'],
        'cctv' => [\App\Models\InvCctv::class, \App\Models\InspeksiCctv::class, 'cctv_id', 'inspection_month', 'inspection_year', 'status'],
        'computer' => [\App\Models\InvComputer::class, \App\Models\InspeksiComputer::class, 'inv_computer_id', 'triwulan', 'year', 'inspection_status'],
        'laptop' => [\App\Models\InvLaptop::class, \App\Models\InspeksiLaptop::class, 'inv_laptop_id', null, 'year', 'inspection_status'],
        'mobile-tower' => [\App\Models\InvMobileTower::class, \App\Models\InspeksiMobileTower::class, 'inv_mt_id', 'month', 'year', 'inspection_status'],
        'nvr' => [\App\Models\InvNvr::class, \App\Models\InspeksiNvr::class, 'nvr_id', 'inspection_month', 'inspection_year', 'status'],
        'panel-box' => [\App\Models\InvPanelBox::class, \App\Models\InspeksiPanelBoxNetwork::class, 'inv_panel_box_id', 'month', 'year', 'inspection_status'],
        'printer' => [\App\Models\InvPrinter::class, \App\Models\InspeksiPrinter::class, 'inv_printer_id', 'month', 'year', 'inspection_status'],
        'scanner' => [\App\Models\InvScanner::class, \App\Models\InspeksiScanner::class, 'inv_scanner_id', 'month', 'year', 'inspection_status'],
        'switch' => [\App\Models\InvSwitch::class, \App\Models\InspeksiSwitch::class, 'inv_switch_id', 'month', 'year', 'inspection_status'],
        'tower' => [\App\Models\InvTower::class, \App\Models\InspeksiTower::class, 'inv_tower_id', 'month', 'year', 'inspection_status'],
        'wireless' => [\App\Models\InvWirelless::class, \App\Models\InspeksiWirelless::class, 'inv_wirelless_id', 'month', 'year', 'inspection_status'],
    ];

    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    public function generate(string $type, string $site): array
    {
        $config = self::TYPES[$type] ?? throw new InvalidArgumentException('Kategori inspeksi tidak valid.');
        if ($type === 'cctv') {
            $this->generate('nvr', $site);
        }
        [$inventoryModel, $inspectionModel, $foreignKey, $periodColumn, $yearColumn, $statusColumn] = $config;
        $now = Carbon::now();
        $created = $updated = 0;

        foreach ($inventoryModel::query()->where('site', $site)->cursor() as $inventory) {
            $query = $inspectionModel::query()->where($foreignKey, $inventory->getKey())->where($yearColumn, $now->year);
            if ($periodColumn === 'triwulan') {
                $query->where($periodColumn, $now->quarter);
            } elseif ($periodColumn) {
                $query->where($periodColumn, $now->month);
            }
            $status = $this->status($type, $inventory->status);
            $inspection = $query->first();
            if (! $inspection) {
                $data = [$foreignKey => $inventory->getKey(), $yearColumn => $now->year, $statusColumn => $status,
                    'inventory_status' => $inventory->status, 'site' => $inventory->site, 'created_date' => $now];
                if ($periodColumn) {
                    $data[$periodColumn] = $periodColumn === 'triwulan' ? $now->quarter : $now->month;
                }
                if (filled($inventory->ip_address ?? null)) {
                    $data['ip_address'] = $inventory->ip_address;
                }
                $inspectionModel::create(array_filter($data, fn ($value) => $value !== null));
                $created++;
            } elseif (in_array($inspection->{$statusColumn}, ['N', null], true) || $status === '-') {
                $inspection->update(array_filter(['inventory_status' => $inventory->status, 'site' => $inventory->site, $statusColumn => $status], fn ($value) => $value !== null));
                $updated++;
            }
        }

        return compact('created', 'updated');
    }

    private function status(string $type, ?string $inventoryStatus): ?string
    {
        $scrap = strtoupper((string) $inventoryStatus) === 'SCRAP';
        if (in_array($type, ['cctv', 'printer', 'scanner', 'nvr'], true)) {
            return $scrap ? '-' : 'N';
        }
        if ($type === 'computer') {
            return $scrap ? null : 'N';
        }

        return 'N';
    }
}
