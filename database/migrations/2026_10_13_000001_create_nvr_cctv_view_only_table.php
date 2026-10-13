<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nvr_cctv_view_only', function (Blueprint $table): void {
            $table->id();
            $table->uuid('nvr_id');
            $table->uuid('cctv_id');
            $table->timestamps();
            $table->unique(['nvr_id', 'cctv_id']);
            $table->foreign('nvr_id')->references('id')->on('inv_nvrs')->cascadeOnDelete();
            $table->foreign('cctv_id')->references('id')->on('inv_cctvs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvr_cctv_view_only');
    }
};
