<?php

namespace App\Http\Controllers\Api;

use App\Models\InspeksiNvr;

class NvrInspectionController extends AbstractVideoInspectionController
{
    protected function inspectionModel(): string
    {
        return InspeksiNvr::class;
    }

    protected function inventoryRelation(): string
    {
        return 'invNvr';
    }

    protected function findingsRelation(): string
    {
        return 'findings';
    }

    protected function findingForeignKey(): string
    {
        return 'inspeksi_nvr_id';
    }

    protected function picaCode(): string
    {
        return 'NVR';
    }

    protected function checklistFields(): array
    {
        return ['q1_accessible', 'q2_time_sync', 'q3_hdd_detected', 'q4_hdd_normal', 'q5_recording_active', 'q6_playback_ok'];
    }
}
