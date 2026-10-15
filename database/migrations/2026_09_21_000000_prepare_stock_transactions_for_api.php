<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel stock_transactions tidak dibuat oleh migration di repo ini;
        // lewati bila belum ada agar `migrate` di server lain tidak gagal.
        if (! Schema::hasTable('stock_transactions')) {
            return;
        }

        if (! Schema::hasColumn('stock_transactions', 'transaction_no')) {
            Schema::table('stock_transactions', function (Blueprint $table): void {
                $table->string('transaction_no')->nullable()->after('site');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE stock_transactions MODIFY transaction_type VARCHAR(32) NOT NULL');
        }

        $indexes = collect(Schema::getIndexes('stock_transactions'))->pluck('name');
        Schema::table('stock_transactions', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains('stock_transactions_number_idx')) {
                $table->index('transaction_no', 'stock_transactions_number_idx');
            }
            if (! $indexes->contains('stock_transactions_site_created_idx')) {
                $table->index(['site', 'created_at'], 'stock_transactions_site_created_idx');
            }
            if (! $indexes->contains('stock_transactions_stock_created_idx')) {
                $table->index(['stock_id', 'created_at'], 'stock_transactions_stock_created_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_transactions')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('stock_transactions'))->pluck('name');
        Schema::table('stock_transactions', function (Blueprint $table) use ($indexes): void {
            foreach (['stock_transactions_number_idx', 'stock_transactions_site_created_idx', 'stock_transactions_stock_created_idx'] as $index) {
                if ($indexes->contains($index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn('stock_transactions', 'transaction_no')) {
            Schema::table('stock_transactions', function (Blueprint $table): void {
                $table->dropColumn('transaction_no');
            });
        }
    }
};
