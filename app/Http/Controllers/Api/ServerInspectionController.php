<?php

namespace App\Http\Controllers\Api;

use App\Models\InspeksiServer;
use Illuminate\Database\Eloquent\Model;

class ServerInspectionController extends AbstractSimpleAssetInspectionController
{
    protected function modelClass(): string
    {
        return InspeksiServer::class;
    }

    protected function relation(): string
    {
        return 'Server';
    }

    protected function deviceType(): string
    {
        return 'server';
    }

    protected function picaCode(): string
    {
        return 'SRV';
    }

    protected function eagerLoads(): array
    {
        return ['Server.serverCategory'];
    }

    protected function checklistFields(): array
    {
        return ['physical_condition', 'power_network_cable', 'asset_label', 'room_temperature', 'room_humidity', 'room_cleanliness',
            'ups_status', 'cpu_memory_fan', 'power_supply', 'hypervisor_health', 'operating_system', 'security_patch', 'firewall_status',
            'antivirus', 'system_log', 'time_synchronization', 'storage_capacity', 'backup_restore_review', 'asset_registered'];
    }

    protected function inventoryResource(Model $inventory): array
    {
        return ['id' => $inventory->getKey(), 'server_code' => $inventory->server_code, 'server_name' => $inventory->server_name,
            'location' => $inventory->location, 'status' => $inventory->status, 'site' => $inventory->site,
            'server_category' => $inventory->serverCategory];
    }
}
