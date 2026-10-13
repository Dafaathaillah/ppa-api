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
