<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvr_cctv_view_only')) {
            return;
        }

        Schema::create('nvr_cctv_view_only', function (Blueprint $table): void {
            $table->id();
            // Tipe kolom mengikuti PK tabel induk (inv_nvrs di sebagian DB masih bigint).
            $this->foreignColumn($table, 'nvr_id', 'inv_nvrs');
            $this->foreignColumn($table, 'cctv_id', 'inv_cctvs');
            $table->timestamps();
            $table->unique(['nvr_id', 'cctv_id']);
            $table->foreign('nvr_id')->references('id')->on('inv_nvrs')->cascadeOnDelete();
            $table->foreign('cctv_id')->references('id')->on('inv_cctvs')->cascadeOnDelete();
        });
    }

    private function foreignColumn(Blueprint $table, string $column, string $parent): void
    {
        $id = collect(Schema::hasTable($parent) ? Schema::getColumns($parent) : [])->firstWhere('name', 'id');
        $type = strtolower((string) ($id['type'] ?? 'char(36)'));

        if (str_contains($type, 'int')) {
            $table->bigInteger($column, false, str_contains($type, 'unsigned'));
        } else {
            $table->uuid($column);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nvr_cctv_view_only');
    }
};
