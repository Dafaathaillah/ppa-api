<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmVhmsNotUpdateRequest;
use App\Models\ListUnit;
use App\Models\RootCauseProblem;
use App\Models\VhmsNotUpdateCase;
use App\Queries\PendingVhmsNotUpdateCases;
use App\Services\ConfirmVhmsNotUpdateCase;
use App\Services\VhmsNotUpdateSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VhmsNotUpdateConfirmationController extends Controller
{
    public function index(string $site, VhmsNotUpdateSynchronizer $sync): Response
    {
        $site = $this->authorizedSite($site); $sync->syncSite($site);
        return Inertia::render('UnitVhms/VhmsNotUpdateConfirmation', [
            'site' => $site, 'conditionOptions' => VhmsNotUpdateCase::CONDITIONS,
            'rootCauses' => RootCauseProblem::query()->whereRaw('UPPER(TRIM(kategori_name)) = ?', ['VHMS'])
                ->orderBy('root_cause_problem')->selectRaw('id_cause as id, root_cause_problem')->get(),
        ]);
    }

    public function data(Request $request, string $site, PendingVhmsNotUpdateCases $cases): JsonResponse
    {
        $site = $this->authorizedSite($site);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'], 'workflow_status' => ['nullable', 'string'],
            'condition' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:cn,sn,model,api_status,source_at,workflow_status'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);
        $p = $cases->query($site, $filters)->paginate((int) ($filters['per_page'] ?? 10));
        return response()->json(['data' => $p->items(), 'meta' => [
            'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(),
            'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem(),
        ]]);
    }

    public function count(string $site): JsonResponse
    {
        $site = $this->authorizedSite($site);
        return response()->json(['pending_count' => VhmsNotUpdateCase::query()->where('site', $site)
            ->where('workflow_status', VhmsNotUpdateCase::PENDING_CONFIRMATION)->count()]);
    }

    public function confirm(ConfirmVhmsNotUpdateRequest $request, string $site, ConfirmVhmsNotUpdateCase $confirm): JsonResponse
    {
        $site = $this->authorizedSite($site); $data = $request->validated();
        DB::transaction(function () use ($data, $site, $request, $confirm): void {
            $cases = VhmsNotUpdateCase::query()->whereIn('id', $data['case_ids'])->lockForUpdate()->get();
            abort_unless($cases->count() === count($data['case_ids']) && $cases->every(fn ($case) => $case->site === $site), 404);
            foreach ($cases as $case) $confirm->handle($case, $data, $request->user());
        });
        return response()->json(['message' => count($data['case_ids']).' unit berhasil dikonfirmasi.']);
    }

    private function authorizedSite(string $site): string
    {
        $site = strtoupper(trim($site)); $user = auth()->user();
        if ($user->role !== 'ict_developer' && strtoupper(trim((string) $user->site)) !== $site) abort(403);
        if (! ListUnit::query()->whereRaw('UPPER(TRIM(site)) = ?', [$site])->exists()) abort(404);
        return $site;
    }
}
