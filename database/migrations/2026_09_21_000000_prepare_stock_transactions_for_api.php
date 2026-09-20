<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_transactions', 'transaction_no')) {
            Schema::table('stock_transactions', function (Blueprint $table): void {
                $table->string('transaction_no')->nullable()->after('site');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE stock_transactions MODIFY transaction_type VARCHAR(32) NOT NULL');
        }

        Schema::table('stock_transactions', function (Blueprint $table): void {
            $table->index('transaction_no', 'stock_transactions_number_idx');
            $table->index(['site', 'created_at'], 'stock_transactions_site_created_idx');
            $table->index(['stock_id', 'created_at'], 'stock_transactions_stock_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table): void {
            $table->dropIndex('stock_transactions_number_idx');
            $table->dropIndex('stock_transactions_site_created_idx');
            $table->dropIndex('stock_transactions_stock_created_idx');
            $table->dropColumn('transaction_no');
        });
    }
};
