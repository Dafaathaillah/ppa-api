<?php

namespace Tests\Feature\Api;

use App\Models\InspeksiLaptop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenericInspectionConsistencyTest extends TestCase
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
        Schema::create('inspeksi_laptops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inv_laptop_id')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('inspection_status')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status_approval')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
        });
    }

    public function test_generic_detail_hides_non_approved_status(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Tech', 'site' => 'BIB', 'role' => 'ict_technician']));
        $inspection = InspeksiLaptop::create(['year' => 2026, 'site' => 'BIB', 'status_approval' => 'pending', 'approved_by' => 'Nobody']);

        $this->getJson("/api/inspections/laptop/{$inspection->id}")
            ->assertOk()
            ->assertJsonPath('data.status_approval', null)
            ->assertJsonPath('data.approved_by', null);
    }
}
