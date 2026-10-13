<?php

namespace Tests\Feature\Api\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsInspectionApiSchema
{
    protected function useInspectionSqliteDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        Schema::connection('sqlite')->getConnection()->reconnect();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('nrp')->nullable();
            $table->string('site')->nullable();
            $table->string('role')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('inv_nvrs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('inventory_number')->nullable();
            $table->string('nvr_name')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inv_cctvs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('nvr_id')->nullable();
            $table->string('cctv_code')->nullable();
            $table->string('cctv_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspeksi_nvrs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('nvr_id');
            $table->unsignedTinyInteger('inspection_month');
            $table->unsignedSmallInteger('inspection_year');
            $table->dateTime('inspection_date')->nullable();
            $table->string('inspector_name')->nullable();
            foreach (['q1_accessible', 'q2_time_sync', 'q3_hdd_detected', 'q4_hdd_normal', 'q5_recording_active', 'q6_playback_ok'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('remark')->nullable();
            $table->string('kondisi')->nullable();
            $table->string('kelayakan')->nullable();
            $table->string('inv_status')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status_approval')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspeksi_cctvs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cctv_id');
            $table->uuid('inspeksi_nvr_id')->nullable();
            $table->unsignedTinyInteger('inspection_month');
            $table->unsignedSmallInteger('inspection_year');
            $table->dateTime('inspection_date')->nullable();
            $table->string('inspector_name')->nullable();
            foreach (['q1_online', 'q2_clear', 'q3_precise', 'q4_recording'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('remark')->nullable();
            $table->string('kondisi')->nullable();
            $table->string('kelayakan')->nullable();
            $table->string('inv_status')->nullable();
            $table->string('status')->nullable();
            $table->string('site')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status_approval')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['nvr', 'cctv'] as $type) {
            Schema::create("inspeksi_{$type}_findings", function (Blueprint $table) use ($type): void {
                $table->uuid('id')->primary();
                $table->uuid("inspeksi_{$type}_id");
                if ($type === 'cctv') {
                    $table->uuid('cctv_id')->nullable();
                }
                $table->string('pica_number')->nullable();
                $table->text('finding')->nullable();
                $table->string('finding_photo')->nullable();
                $table->text('action_taken')->nullable();
                $table->string('action_photo')->nullable();
                $table->date('due_date')->nullable();
                $table->string('pic_name')->nullable();
                $table->text('remark')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('pica_inspeksis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('pica_number')->nullable();
            $table->uuid('inspeksi_id')->nullable();
            $table->text('temuan')->nullable();
            $table->text('tindakan')->nullable();
            $table->string('foto_temuan')->nullable();
            $table->string('foto_tindakan')->nullable();
            $table->date('due_date')->nullable();
            $table->text('remark')->nullable();
            $table->string('status_pica')->nullable();
            $table->string('site')->nullable();
            $table->string('close_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
