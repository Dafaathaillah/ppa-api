<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiCctv;
use App\Models\InvCctv;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsInspectionApiSchema;
use Tests\TestCase;

class CctvInspectionApiTest extends TestCase
{
    use BuildsInspectionApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useInspectionSqliteDatabase();
    }

    public function test_technician_can_list_and_process_own_site_cctv_inspection(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $inventory = InvCctv::create(['cctv_code' => 'BIB-CCTV-001', 'cctv_name' => 'Camera 1', 'site' => 'BIB', 'status' => 'READY_USED']);
        $inspection = InspeksiCctv::create(['cctv_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'BIB']);

        $this->getJson('/api/inspections/cctv?site=BIB&month=10&year=2026')
            ->assertOk()->assertJsonPath('data.0.id', $inspection->id);

        $this->postJson("/api/inspections/cctv/{$inspection->id}/process", [
            'q1_online' => 'Ya', 'q2_clear' => 'Ya', 'q3_precise' => 'Ya', 'q4_recording' => 'Ya',
            'condition' => 'Bagus', 'worthiness' => 'Layak', 'inventory_status' => 'READY_STANDBY',
            'remark' => 'Normal', 'findings' => [],
        ])->assertOk()->assertJsonPath('data.status', 'Y');

        $this->assertDatabaseHas('inv_cctvs', ['id' => $inventory->id, 'status' => 'READY_STANDBY']);
    }

    public function test_only_group_leader_can_approve_completed_cctv_inspection(): void
    {
        $inventory = InvCctv::create(['site' => 'BIB', 'status' => 'READY_USED']);
        $inspection = InspeksiCctv::create(['cctv_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'BIB', 'status' => 'Y']);
        Sanctum::actingAs(User::create(['name' => 'Leader', 'site' => 'BIB', 'role' => 'ict_group_leader']));

        $this->postJson("/api/inspections/cctv/{$inspection->id}/approval", ['approval_type' => 'accept'])
            ->assertOk()->assertJsonPath('data.status_approval', 'approved');
    }
}
