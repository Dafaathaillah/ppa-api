<?php

namespace App\Services;

use App\Models\VhmsNotUpdateCase;
use App\Queries\LatestVhmsProblemUnits;

class VhmsNotUpdateSynchronizer
{
    public function __construct(private readonly LatestVhmsProblemUnits $latestProblems) {}

    public function syncSite(string $site): int
    {
        $site = strtoupper(trim($site));
        $created = 0;

        $this->latestProblems->queryForSite($site)->get()->each(function ($row) use (&$created): void {
                    $sourceKey = hash('sha256', implode('|', [strtolower(trim($row->site)), strtolower(trim($row->sn)), strtolower(trim($row->cn)), $row->kpi_id, $row->kpi_date]));
                    $case = VhmsNotUpdateCase::firstOrCreate(['source_key' => $sourceKey], [
                        'list_unit_id' => $row->id, 'site' => strtoupper(trim($row->site)),
                        'source_record_id' => $row->kpi_id, 'api_status' => $row->kpi_status,
                        'source_at' => $row->kpi_date, 'workflow_status' => VhmsNotUpdateCase::PENDING_CONFIRMATION,
                    ]);
                    $created += $case->wasRecentlyCreated ? 1 : 0;
            });

        return $created;
    }
}
