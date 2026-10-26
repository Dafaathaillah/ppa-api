<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiMobileTower;
use App\Models\InspeksiPanelBoxNetwork;
use App\Models\InvMobileTower;
use App\Models\InvPanelBox;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpecializedInspectionConsistencyTest extends TestCase
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
        Schema::create('inv_mobile_towers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('inventory_number')->nullable();
            $table->string('mt_code')->nullable();
            $table->string('type_mt')->nullable();
            $table->string('location')->nullable();
            $table->string('detail_location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
        });
        Schema::create('inspeksi_mobile_towers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inv_mt_id')->nullable();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('inspection_status')->nullable();
            $table->dateTime('inspection_at')->nullable();
            $table->string('pica_number')->nullable();
            $table->string('condition')->nullable();
            $table->text('remarks')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status_approval')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
        });
        Schema::create('inspeksi_mobile_tower_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inspeksi_mobile_tower_id');
            $table->timestamps();
        });
        Schema::create('inv_panel_boxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('inventory_number')->nullable();
            $table->string('item_name')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inspeksi_panel_box_networks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inv_panel_box_id')->nullable();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('inspection_status')->nullable();
            $table->dateTime('inspection_at')->nullable();
            $table->string('pica_number')->nullable();
            $table->string('conditions')->nullable();
            $table->text('remarks')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status_approval')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
        });
        Schema::create('inspeksi_panel_box_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inspeksi_panel_box_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_mobile_tower_searches_inventory_and_hides_pending_approval(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $match = InvMobileTower::create(['inventory_number' => 'MT-SEARCH-01', 'location' => 'Workshop', 'site' => 'BIB']);
        $other = InvMobileTower::create(['inventory_number' => 'MT-OTHER-01', 'location' => 'Office', 'site' => 'BIB']);
        $inspection = InspeksiMobileTower::create(['inv_mt_id' => $match->id, 'month' => 10, 'year' => 2026, 'site' => 'BIB', 'status_approval' => 'pending', 'approved_by' => 'Nobody']);
        InspeksiMobileTower::create(['inv_mt_id' => $other->id, 'month' => 10, 'year' => 2026, 'site' => 'BIB']);

        $this->getJson('/api/inspections/mobile-towers?search=MT-SEARCH')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inspection->id)
            ->assertJsonPath('data.0.status_approval', null)->assertJsonPath('data.0.approved_by', null);
    }

    public function test_panel_box_searches_inventory_and_hides_rejected_approval(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $match = InvPanelBox::create(['inventory_number' => 'PB-SEARCH-01', 'location' => 'Workshop', 'site' => 'BIB']);
        $other = InvPanelBox::create(['inventory_number' => 'PB-OTHER-01', 'location' => 'Office', 'site' => 'BIB']);
        $inspection = InspeksiPanelBoxNetwork::create(['inv_panel_box_id' => $match->id, 'month' => 10, 'year' => 2026, 'site' => 'BIB', 'status_approval' => 'reject', 'approved_by' => 'Leader']);
        InspeksiPanelBoxNetwork::create(['inv_panel_box_id' => $other->id, 'month' => 10, 'year' => 2026, 'site' => 'BIB']);

        $this->getJson('/api/inspections/panel-boxes?search=PB-SEARCH')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inspection->id)
            ->assertJsonPath('data.0.status_approval', null)->assertJsonPath('data.0.approved_by', null);
    }
}
