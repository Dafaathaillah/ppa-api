<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiScanner;
use App\Models\InvScanner;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsInspectionApiSchema;
use Tests\TestCase;

class InspectionSiteAuthorizationTest extends TestCase
{
    use BuildsInspectionApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useInspectionSqliteDatabase();

        Schema::create('inv_scanners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scanner_code')->nullable();
            $table->string('item_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspeksi_scanners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inv_scanner_id');
            $table->string('site')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->dateTime('inspection_at')->nullable();
            $table->string('inspection_status')->nullable();
            $table->string('status_approval')->nullable();
            $table->string('condition')->nullable();
            $table->string('inventory_status')->nullable();
            $table->string('inspector')->nullable();
            $table->string('remark')->nullable();
            $table->string('pica_number')->nullable();
            $table->string('approved_by')->nullable();
            foreach (['fisik', 'kaca', 'kabel', 'power', 'mekanik', 'hasil_scan'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['findings', 'findings_action', 'findings_status', 'findings_image', 'action_image', 'inspection_image', 'due_date'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_specialized_inspection_list_and_detail_are_limited_to_the_authenticated_site(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech BIB', 'site' => 'BIB', 'role' => 'ict_technician']));
        $own = $this->scanner('BIB', 'BIB-SCN-001');
        $other = $this->scanner('AMI', 'AMI-SCN-001');

        $this->getJson('/api/inspections/scanners?site=AMI')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->getJson("/api/inspections/scanners/{$other->id}")->assertNotFound();
    }

    public function test_scanner_search_filters_inventory_and_only_exposes_approved_status(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech BIB', 'site' => 'BIB', 'role' => 'ict_technician']));
        $pending = $this->scanner('BIB', 'BIB-SCN-001');
        $pending->update(['status_approval' => 'pending']);
        $approved = $this->scanner('BIB', 'BIB-SCN-002');
        $approved->Scanner->update(['ip_address' => '172.16.20.22']);
        $approved->update(['status_approval' => 'approve', 'approved_by' => 'Leader']);

        $this->getJson('/api/inspections/scanners?search=SCN-002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.status_approval', 'approved');

        $this->getJson("/api/inspections/scanners/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.status_approval', null)
            ->assertJsonPath('data.approved_by', null);

        $this->getJson('/api/inspections/scanners?search=172.16.20.22')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    private function scanner(string $site, string $code): InspeksiScanner
    {
        $inventory = InvScanner::create(['scanner_code' => $code, 'site' => $site, 'status' => 'READY_USED']);

        return InspeksiScanner::create([
            'inv_scanner_id' => $inventory->id,
            'site' => $site,
            'month' => 10,
            'year' => 2026,
        ]);
    }
}
