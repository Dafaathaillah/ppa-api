<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiNvr;
use App\Models\InvNvr;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsInspectionApiSchema;
use Tests\TestCase;

class NvrInspectionApiTest extends TestCase
{
    use BuildsInspectionApiSchema;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useInspectionSqliteDatabase();
    }

    public function test_technician_can_list_and_process_own_site_nvr_inspection(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $inventory = InvNvr::create(['inventory_number' => 'BIB-NVR-001', 'nvr_name' => 'NVR 1', 'site' => 'BIB', 'status' => 'READY_USED']);
        $inspection = InspeksiNvr::create(['nvr_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'BIB']);

        $this->getJson('/api/inspections/nvrs?site=BIB&month=10&year=2026')
            ->assertOk()->assertJsonPath('data.0.id', $inspection->id);

        $this->postJson("/api/inspections/nvrs/{$inspection->id}/process", [
            'q1_accessible' => 'Ya', 'q2_time_sync' => 'Ya', 'q3_hdd_detected' => 'Ya',
            'q4_hdd_normal' => 'Ya', 'q5_recording_active' => 'Ya', 'q6_playback_ok' => 'Ya',
            'condition' => 'Bagus', 'worthiness' => 'Layak', 'inventory_status' => 'READY_STANDBY',
            'remark' => 'Normal', 'findings' => [],
        ])->assertOk()->assertJsonPath('data.status', 'Y');

        $this->assertDatabaseHas('inv_nvrs', ['id' => $inventory->id, 'status' => 'READY_STANDBY']);
    }

    public function test_nvr_processing_is_rejected_for_another_site(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $inventory = InvNvr::create(['site' => 'AMI', 'status' => 'READY_USED']);
        $inspection = InspeksiNvr::create(['nvr_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'AMI']);

        $this->postJson("/api/inspections/nvrs/{$inspection->id}/process", [])->assertNotFound();
    }
}
