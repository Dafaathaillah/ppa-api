<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inspeksi_switches', 'inspeksi_wirellesses'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $this->addString($tableName, 'site');
            $this->addString($tableName, 'inspection_by');
            $this->addBoolean($tableName, 'has_findings');
            $this->addText($tableName, 'corrective_action');
            $this->addString($tableName, 'corrective_action_image');
            $this->addString($tableName, 'finding_status');
            $this->addDate($tableName, 'target_completion_date');
        }

        if (Schema::hasTable('inspeksi_towers')) {
            $this->addString('inspeksi_towers', 'kelayakan');
            $this->addString('inspeksi_towers', 'inventory_status');
            $this->addText('inspeksi_towers', 'inspection_image');
            $this->addText('inspeksi_towers', 'checklist_results_list');
            $this->addText('inspeksi_towers', 'list_results_remark');
        }

        if (Schema::hasTable('inspeksi_servers')) {
            $this->addString('inspeksi_servers', 'inventory_status');
            $this->addText('inspeksi_servers', 'inspection_image');
        }
    }

    public function down(): void
    {
        // Compatibility migration: columns may predate this repository, so dropping them is unsafe.
    }

    private function addString(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->string($column)->nullable());
        }
    }

    private function addText(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->text($column)->nullable());
        }
    }

    private function addBoolean(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->boolean($column)->default(false));
        }
    }

    private function addDate(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->date($column)->nullable());
        }
    }
};
