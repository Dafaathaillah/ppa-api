<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mutasi_assets')) {
            return;
        }

        Schema::table('mutasi_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('mutasi_assets', 'reject_reason')) {
                $table->text('reject_reason')->nullable();
            }
            if (! Schema::hasColumn('mutasi_assets', 'rejected_by')) {
                $table->string('rejected_by')->nullable();
            }
            if (! Schema::hasColumn('mutasi_assets', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('mutasi_assets'))->pluck('name');
        Schema::table('mutasi_assets', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains('mutasi_assets_departure_status_idx')) {
                $table->index(['departure_site', 'approval_status'], 'mutasi_assets_departure_status_idx');
            }
            if (! $indexes->contains('mutasi_assets_arrived_status_idx')) {
                $table->index(['arrived_site', 'approval_status'], 'mutasi_assets_arrived_status_idx');
            }
            if (! $indexes->contains('mutasi_assets_created_date_idx')) {
                $table->index('created_date', 'mutasi_assets_created_date_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mutasi_assets')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('mutasi_assets'))->pluck('name');
        Schema::table('mutasi_assets', function (Blueprint $table) use ($indexes): void {
            foreach (['mutasi_assets_departure_status_idx', 'mutasi_assets_arrived_status_idx', 'mutasi_assets_created_date_idx'] as $index) {
                if ($indexes->contains($index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
