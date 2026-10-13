<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiCctv;
use App\Models\InspeksiCctvFinding;
use App\Models\InspeksiNvr;
use App\Models\InvCctv;
use App\Models\InvNvr;
use App\Models\PicaInspeksi;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsInspectionApiSchema;
use Tests\TestCase;

class PicaVideoInspectionApiTest extends TestCase
{
    use BuildsInspectionApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useInspectionSqliteDatabase();
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
    }

    public function test_meta_exposes_flutter_device_type_options(): void
    {
        $this->getJson('/api/pica-inspeksi/meta?site=BIB')->assertOk()
            ->assertJsonFragment(['value' => 'NVR', 'label' => 'NVR'])
            ->assertJsonFragment(['value' => 'CCTV', 'label' => 'CCTV']);
    }

    public function test_nvr_pica_is_supported_and_closed_items_are_hidden_by_default(): void
    {
        $inventory = InvNvr::create(['site' => 'BIB']);
        $inspection = InspeksiNvr::create(['nvr_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'BIB']);
        $open = PicaInspeksi::create(['inspeksi_id' => $inspection->id, 'pica_number' => 'BIB/PICA/NVR/001', 'status_pica' => 'OPEN', 'site' => 'BIB']);
        PicaInspeksi::create(['inspeksi_id' => $inspection->id, 'pica_number' => 'BIB/PICA/NVR/002', 'status_pica' => 'CLOSED', 'site' => 'BIB']);

        $this->getJson('/api/pica-inspeksi?device_type=NVR&site=BIB')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);

        $this->getJson("/api/pica-inspeksi/{$open->id}?site=BIB&device_type=NVR")
            ->assertOk()->assertJsonPath('data.device_type', 'NVR')
            ->assertJsonPath('data.device_code', '-');

        $this->patchJson("/api/pica-inspeksi/{$open->id}", [
            'site' => 'BIB', 'device_type' => 'NVR', 'temuan' => 'Disk warning',
            'findings_status' => 'CLOSED', 'remark' => 'Replaced',
        ])->assertOk();
        $this->assertDatabaseHas('pica_inspeksis', ['id' => $open->id, 'temuan' => 'Disk warning', 'status_pica' => 'CLOSED']);
    }

    public function test_cctv_pica_filters_finding_status(): void
    {
        $inventory = InvCctv::create(['site' => 'BIB']);
        $inspection = InspeksiCctv::create(['cctv_id' => $inventory->id, 'inspection_month' => 10, 'inspection_year' => 2026, 'site' => 'BIB']);
        $finding = InspeksiCctvFinding::create(['inspeksi_cctv_id' => $inspection->id, 'cctv_id' => $inventory->id, 'finding' => 'Blur', 'status' => 'OPEN']);

        $this->getJson('/api/pica-inspeksi?device_type=CCTV&site=BIB&status_pica=OPEN')
            ->assertOk()->assertJsonPath('data.0.id', $inspection->id)
            ->assertJsonPath('data.0.findings.0.status', 'OPEN');

        $this->getJson("/api/pica-inspeksi/{$inspection->id}?site=BIB&device_type=CCTV")
            ->assertOk()->assertJsonPath('data.device_type', 'CCTV');

        $this->patchJson("/api/pica-inspeksi/{$inspection->id}", [
            'site' => 'BIB', 'device_type' => 'CCTV', 'finding_id' => $finding->id,
            'temuan' => 'Lens blur', 'tindakan' => 'Clean lens', 'findings_status' => 'CLOSED',
        ])->assertOk();
        $this->assertDatabaseHas('inspeksi_cctv_findings', ['id' => $finding->id, 'finding' => 'Lens blur', 'status' => 'CLOSED']);
    }
}
