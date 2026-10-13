<?php

namespace App\Http\Controllers\Api;

use App\Models\InspeksiCctv;

class CctvInspectionController extends AbstractVideoInspectionController
{
    protected function inspectionModel(): string
    {
        return InspeksiCctv::class;
    }

    protected function inventoryRelation(): string
    {
        return 'cctv';
    }

    protected function findingsRelation(): string
    {
        return 'findings';
    }

    protected function findingForeignKey(): string
    {
        return 'inspeksi_cctv_id';
    }

    protected function picaCode(): string
    {
        return 'CCTV';
    }

    protected function checklistFields(): array
    {
        return ['q1_online', 'q2_clear', 'q3_precise', 'q4_recording'];
    }
}
