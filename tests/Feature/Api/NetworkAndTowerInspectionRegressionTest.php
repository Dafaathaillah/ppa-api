<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiTower;
use App\Models\InvTower;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NetworkAndTowerInspectionRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        Schema::connection('sqlite')->getConnection()->reconnect();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('site')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('category_towers', function (Blueprint $table): void {
            $table->id();
            $table->string('category_name')->nullable();
            $table->timestamps();
        });

        Schema::create('inv_towers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('tower_code')->nullable();
            $table->string('location')->nullable();
            $table->string('detail_location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->string('remark_inspeksi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspeksi_towers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inv_tower_id');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('inspection_status')->nullable();
            $table->dateTime('inspection_at')->nullable();
            $table->string('status_approval')->nullable();
            $table->timestamps();
        });

        Schema::create('pica_inspeksis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inspeksi_id')->nullable();
            $table->string('pica_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['inspeksi_switches', 'inspeksi_wirellesses'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->unsignedBigInteger($tableName === 'inspeksi_switches' ? 'inv_switch_id' : 'inv_wirelless_id');
                $table->string('site')->nullable();
                $table->unsignedTinyInteger('month')->nullable();
                $table->unsignedSmallInteger('year')->nullable();
                $table->string('inspection_status')->nullable();
                $table->string('pica_number')->nullable();
                $table->string('condition')->nullable();
                $table->string('device_status')->nullable();
                $table->string('inventory_status')->nullable();
                $table->string('findings')->nullable();
                $table->string('findings_image')->nullable();
                $table->string('findings_action')->nullable();
                $table->string('action_image')->nullable();
                $table->string('findings_status')->nullable();
                $table->date('due_date')->nullable();
                $table->string('remarks')->nullable();
                $table->dateTime('inspection_at')->nullable();
                $table->timestamps();
            });
        }

        foreach (['inv_switches', 'inv_wirellesses'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('inventory_number')->nullable();
                $table->string('device_name')->nullable();
                $table->string('location')->nullable();
                $table->string('status')->nullable();
                $table->string('site')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function test_technician_can_list_tower_inspections_with_inventory_relation(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $tower = InvTower::create(['tower_code' => 'BIB-TWR-001', 'site' => 'BIB', 'status' => 'READY_USED']);
        $inspection = InspeksiTower::create(['inv_tower_id' => $tower->id, 'month' => 10, 'year' => 2026]);

        $this->getJson('/api/inspections/towers?month=10&year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.id', $inspection->id)
            ->assertJsonPath('data.0.inventory.tower_code', 'BIB-TWR-001');
    }

    public function test_network_inspection_routes_accept_legacy_numeric_ids(): void
    {
        $switchRoute = Route::getRoutes()->match(Request::create('/api/inspections/switches/123', 'GET'));
        $wirelessRoute = Route::getRoutes()->match(Request::create('/api/inspections/wireless/456', 'GET'));
        $towerInventoryRoute = Route::getRoutes()->match(Request::create('/api/inventory/towers/789', 'GET'));

        $this->assertSame(
            \App\Http\Controllers\Api\SwitchInspectionController::class.'@show',
            $switchRoute->getActionName()
        );
        $this->assertSame(
            \App\Http\Controllers\Api\WirelessInspectionController::class.'@show',
            $wirelessRoute->getActionName()
        );
        $this->assertSame(
            \App\Http\Controllers\Api\InventoryTowerController::class.'@show',
            $towerInventoryRoute->getActionName()
        );
    }

    public function test_schema_alignment_migration_adds_columns_used_by_network_and_tower_api(): void
    {
        $migration = require database_path('migrations/2026_10_26_000001_align_inspection_api_schema.php');
        $migration->up();

        foreach (['inspeksi_switches', 'inspeksi_wirellesses'] as $tableName) {
            foreach (['inspection_by', 'has_findings', 'corrective_action', 'corrective_action_image', 'finding_status', 'target_completion_date'] as $column) {
                $this->assertTrue(Schema::hasColumn($tableName, $column), "Missing {$tableName}.{$column}");
            }
        }

        foreach (['inspection_image', 'inventory_status', 'checklist_results_list', 'list_results_remark'] as $column) {
            $this->assertTrue(Schema::hasColumn('inspeksi_towers', $column), "Missing inspeksi_towers.{$column}");
        }
    }

    public function test_technician_can_list_and_process_legacy_network_inspections(): void
    {
        (require database_path('migrations/2026_10_26_000001_align_inspection_api_schema.php'))->up();
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));

        foreach ([
            ['type' => 'switches', 'inventory' => 'inv_switches', 'inspection' => 'inspeksi_switches', 'foreign_key' => 'inv_switch_id'],
            ['type' => 'wireless', 'inventory' => 'inv_wirellesses', 'inspection' => 'inspeksi_wirellesses', 'foreign_key' => 'inv_wirelless_id'],
        ] as $fixture) {
            $inventoryId = DB::table($fixture['inventory'])->insertGetId([
                'inventory_number' => 'BIB-'.strtoupper($fixture['type']).'-001', 'device_name' => 'Network Device',
                'location' => 'Server Room', 'status' => 'READY_USED', 'site' => 'BIB',
            ]);
            $inspectionId = DB::table($fixture['inspection'])->insertGetId([
                $fixture['foreign_key'] => $inventoryId, 'site' => 'BIB', 'month' => 10, 'year' => 2026,
            ]);

            $this->getJson("/api/inspections/{$fixture['type']}?month=10&year=2026")
                ->assertOk()->assertJsonPath('data.0.id', $inspectionId);
            $this->postJson("/api/inspections/{$fixture['type']}/{$inspectionId}/process", [
                'device_status' => 'ONLINE', 'condition' => 'LAYAK', 'inventory_status' => 'READY_STANDBY',
                'location' => 'Rack 1', 'remarks' => 'Normal', 'has_findings' => false,
            ])->assertOk()->assertJsonPath('data.inspection_status', 'Y');

            $this->assertDatabaseHas($fixture['inventory'], ['id' => $inventoryId, 'status' => 'READY_STANDBY', 'location' => 'Rack 1']);
        }
    }

    public function test_server_inspection_rejects_photo_over_ten_megabytes_as_json(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));

        $this->post('/api/inspections/servers/00000000-0000-4000-8000-000000000001/process', [
            'image_inspeksi' => UploadedFile::fake()->image('inspection.jpg')->size(10241),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('image_inspeksi');
    }
}
