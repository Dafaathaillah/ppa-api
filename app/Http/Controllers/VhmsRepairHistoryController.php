<?php

namespace App\Http\Controllers;

use App\Models\ListUnit;
use App\Queries\VhmsRepairHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VhmsRepairHistoryController extends Controller
{
    public function index(string $site, VhmsRepairHistory $history): Response
    {
        $site = $this->authorizedSite($site);
        return Inertia::render('UnitVhms/VhmsRepairHistory', [
            'site' => $site,
            'initialHistory' => $this->pagePayload($history->queryForSite($site)->paginate(10)),
        ]);
    }

    public function data(Request $request, string $site, VhmsRepairHistory $history): JsonResponse
    {
        $site = $this->authorizedSite($site);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,25,50'], 'sort' => ['nullable', 'string', 'in:cn,model,status,status_vhms,repair_started_at,root_cause,action,repair_note,checking_by,created_at'], 'direction' => ['nullable', 'string', 'in:asc,desc']]);
        $page = $history->queryForSite($site, $validated['search'] ?? null, $validated['sort'] ?? 'created_at', $validated['direction'] ?? 'desc')->paginate((int) ($validated['per_page'] ?? 10));
        return response()->json($this->pagePayload($page));
    }

    private function authorizedSite(string $site): string
    {
        $site = strtoupper(trim($site)); $user = auth()->user();
        if ($user->role !== 'ict_developer' && strtoupper(trim((string) $user->site)) !== $site) abort(403);
        if (! ListUnit::query()->whereRaw('UPPER(TRIM(site)) = ?', [$site])->exists()) abort(404);
        return $site;
    }

    private function pagePayload($page): array
    {
        return ['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
            'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ]];
    }
}
