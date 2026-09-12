<?php

namespace App\Services;

use App\Models\InspeksiAccessPoint;
use App\Models\InspeksiMobileTowerDevice;
use App\Models\InspeksiPanelBoxDevice;
use App\Models\InspeksiSwitch;
use App\Models\InspeksiWirelless;
use App\Models\PicaInspeksi;
use App\Support\InspectionPicaNumber;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InspectionAttachedDeviceProcessor
{
    private const TYPES = [
        'ACCESS_POINT' => [InspeksiAccessPoint::class, 'AP'],
        'SWITCH' => [InspeksiSwitch::class, 'SW'],
        'WIRELLESS' => [InspeksiWirelless::class, 'BB'],
    ];

    public function replace(Model $container, string $containerType, array $devices, Model $actor, TowerInspectionPhotoStorage $photos): void
    {
        $pivotClass = $containerType === 'mobile_tower' ? InspeksiMobileTowerDevice::class : InspeksiPanelBoxDevice::class;
        $foreignKey = $containerType === 'mobile_tower' ? 'inspeksi_mobile_tower_id' : 'inspeksi_panel_box_id';

        $resolved = collect($devices)->map(function (array $device, int $index) use ($container): array {
            [$inspectionClass, $prefix] = self::TYPES[$device['type']];
            $inspection = $inspectionClass::with('inventory')->find($device['inspection_id']);
            if (! $inspection || strtoupper((string) $inspection->site) !== strtoupper((string) $container->site)) {
                throw ValidationException::withMessages(["devices.{$index}.inspection_id" => 'Perangkat harus ada dan berasal dari site inspeksi yang sama.']);
            }

            return [$device, $inspection, $prefix];
        });

        $pivotClass::where($foreignKey, $container->id)->delete();

        foreach ($resolved as [$device, $inspection, $prefix]) {
            $pivotData = [
                $foreignKey => $container->id,
                'device_type' => $device['type'],
                'device_inspection_id' => $inspection->id,
                'inventory_number' => $device['inventory_number'] ?? $inspection->inventory?->inventory_number,
                'month' => $container->month,
                'year' => $container->year,
            ];
            $containerType === 'panel_box' ? $pivotClass::saveForPeriod($pivotData) : $pivotClass::create($pivotData);

            $hasFindings = (bool) ($device['has_findings'] ?? filled($device['findings'] ?? null));
            $findingImage = isset($device['findings_image']) ? $photos->store($device['findings_image']) : null;
            $actionImage = isset($device['corrective_action_image']) ? $photos->store($device['corrective_action_image']) : null;
            $picaNumber = $hasFindings ? ($inspection->pica_number ?: InspectionPicaNumber::next($container->site, $prefix)) : null;

            $inspection->update([
                'inspection_status' => 'Y', 'inspection_at' => now(), 'inspection_by' => $actor->name,
                'device_status' => $device['device_status'] ?? null, 'condition' => $device['condition'] ?? null,
                'inventory_status' => $device['inventory_status'], 'remarks' => $device['remarks'] ?? null,
                'has_findings' => $hasFindings, 'findings' => $hasFindings ? ($device['findings'] ?? null) : null,
                'findings_image' => $hasFindings ? $findingImage : null,
                'corrective_action' => $hasFindings ? ($device['corrective_action'] ?? null) : null,
                'corrective_action_image' => $hasFindings ? $actionImage : null,
                'finding_status' => $hasFindings ? ($device['finding_status'] ?? null) : null,
                'target_completion_date' => $hasFindings ? ($device['target_completion_date'] ?? null) : null,
                'pica_number' => $picaNumber,
                'is_panelbox_inspection' => $containerType === 'panel_box',
                'panelbox_inspection_id' => $containerType === 'panel_box' ? $container->id : null,
            ]);
            $inspection->inventory?->update([
                'status' => $device['inventory_status'],
                'inspection_remark' => $device['remarks'] ?? null,
            ]);

            $pica = PicaInspeksi::where('inspeksi_id', $inspection->id)->first();
            if (! $hasFindings) {
                $pica?->delete();

                continue;
            }
            $picaData = [
                'pica_number' => $picaNumber, 'temuan' => $device['findings'] ?? null,
                'foto_temuan' => $findingImage, 'tindakan' => $device['corrective_action'] ?? null,
                'foto_tindakan' => $actionImage, 'status_pica' => $device['finding_status'] ?? null,
                'due_date' => $device['target_completion_date'] ?? null, 'remark' => $device['remarks'] ?? null,
                'close_by' => $actor->name, 'site' => $container->site,
            ];
            $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $inspection->id]);
        }
    }
}
