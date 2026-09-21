<?php

namespace App\Http\Controllers;

use App\Exports\UnitProblemVhmsExport;
use App\Http\Requests\StoreUnitVhmsRepairRequest;
use App\Models\ListUnit;
use App\Models\RootCauseProblem;
use App\Models\User;
use App\Models\VhmsNotUpdateCase;
use App\Models\VhmsUnitConditionPeriod;
use App\Queries\LatestVhmsProblemUnits;
use App\Services\VhmsApiUnitSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use App\Support\OperationalShift;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UnitProblemVhmsController extends Controller
{
    private const STATUSES = ['TERDOWNLOAD', 'TIDAK_TERDOWNLOAD', 'PROBLEM', 'OBSERVASI'];
    private const UNIT_STATUSES = ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SERVICE', 'CONTROLLER_PROBLEM', 'SCRAP'];

    private const ACTIONS = [
        'Reconnect CN5',
        'Krimping Ulang Konektor',
        'Ganti Wiring',
        'Ganti Wireless',
        'Setting Ulang Wireless',
        'Perlu Install Ulang',
        'Reconnect Power',
        'Pergantian Perangkat',
    ];

    public function index(Request $request, string $site, LatestVhmsProblemUnits $problems, VhmsApiUnitSynchronizer $sync): Response
    {
        $site = $this->authorizedSite($site);
        $onlyNotReady = $request->boolean('not_ready');
        $initialPage = $problems->queryForSite($site, null, $onlyNotReady)
            ->paginate(10, ['*'], 'page', 1);
        $availableSites = auth()->user()->role === 'ict_developer'
            ? ListUnit::query()
                ->selectRaw('UPPER(TRIM(site)) as site_code')
                ->whereNotNull('site')
                ->whereRaw("TRIM(site) <> ''")
                ->distinct()
                ->orderBy('site_code')
                ->pluck('site_code')
                ->values()
            : collect([$site]);

        return Inertia::render('UnitVhms/UnitProblemVhms', [
            'site' => $site,
            'availableSites' => $availableSites,
            'rootCauses' => RootCauseProblem::query()
                ->whereRaw('UPPER(TRIM(kategori_name)) = ?', ['VHMS'])
                ->orderBy('root_cause_problem')
                ->selectRaw('id_cause as id, root_cause_problem')
                ->get(),
            'checkingUsers' => User::query()
                ->whereRaw('UPPER(TRIM(site)) = ?', [$site])
                ->whereRaw('UPPER(TRIM(ict_group)) = ?', ['Y'])
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values(),
            'statusOptions' => self::STATUSES,
            'unitStatusOptions' => self::UNIT_STATUSES,
            'onlyNotReady' => $onlyNotReady,
            'actionOptions' => self::ACTIONS,
            'initialUnits' => $this->pagePayload($initialPage),
            'initialSync' => $sync->currentMetadata($site),
        ]);
    }

    public function data(Request $request, string $site, LatestVhmsProblemUnits $problems, VhmsApiUnitSynchronizer $sync): JsonResponse
    {
        $site = $this->authorizedSite($site);
        $this->normalizeBooleanFilter($request, 'not_ready');
        $this->normalizeBooleanFilter($request, 'refresh');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'not_ready' => ['nullable', 'boolean'],
            'refresh' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:cn,sn,model,ip_wirelless,mac_wirelless,tipe_wirelless,status,status_vhms,last_download,last_operation,remark'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);
        $syncMeta = ($validated['refresh'] ?? false)
            ? $sync->sync($site, true)
            : $sync->currentMetadata($site);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);
        $paginator = $problems->queryForSite($site, $validated['search'] ?? null, (bool) ($validated['not_ready'] ?? false), $validated['sort'] ?? 'cn', $validated['direction'] ?? 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            ...$this->pagePayload($paginator),
            'sync' => $syncMeta,
        ]);
    }

    public function export(Request $request, string $site): BinaryFileResponse
    {
        $site = $this->authorizedSite($site);
        $this->normalizeBooleanFilter($request, 'not_ready');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'not_ready' => ['nullable', 'boolean'],
        ]);

        return Excel::download(
            new UnitProblemVhmsExport($site, $validated['search'] ?? null, (bool) ($validated['not_ready'] ?? false)),
            "list-unit-problem-{$site}.xlsx"
        );
    }

    public function store(
        StoreUnitVhmsRepairRequest $request,
        string $site,
        ListUnit $unit
    ): JsonResponse|RedirectResponse {
        $site = $this->authorizedSite($site);
        $this->ensureUnitSite($unit, $site);
        $validated = $request->validated();
        $rootCause = RootCauseProblem::query()->findOrFail($validated['root_cause_problem_id']);
        $user = $request->user();
        $operational = OperationalShift::from(now());

        DB::transaction(function () use ($unit, $validated, $rootCause, $user, $operational): void {
            $unit->repairs()->create([
                'vhms_not_update_case_id' => null,
                'status_vhms' => $unit->status_vhms ?: 'unknown',
                'status' => $validated['status'],
                'shift' => $operational->shift,
                'operational_date' => $operational->operationalDate,
                'repair_started_at' => $validated['repair_started_at'],
                'repair_ended_at' => $validated['repair_ended_at'] ?? null,
                'root_cause_problem_id' => $rootCause->getKey(),
                'root_cause' => $rootCause->root_cause_problem,
                'action' => $validated['action'],
                'repair_note' => $validated['repair_note'],
                'checking_by' => $validated['checking_by'],
                'updated_by' => $user->name,
            ]);

            $unit->update([
                'status' => $validated['status'],
                'remark' => $validated['repair_note'],
                'last_update_at' => now(),
                'last_update_by' => $user->name,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Perbaikan VHMS berhasil disimpan.',
                'unit' => $unit->fresh()->only([
                    'id',
                    'ip_wirelless',
                    'mac_wirelless',
                    'tipe_wirelless',
                    'status_vhms',
                    'status',
                    'remark',
                    'last_update_at',
                    'last_update_by',
                ]),
            ]);
        }

        return redirect()
            ->route('unit-vhms.problems.index', ['site' => $site])
            ->with('success', 'Perbaikan VHMS berhasil disimpan.');
    }

    public function history(string $site, ListUnit $unit): JsonResponse
    {
        $site = $this->authorizedSite($site);
        $this->ensureUnitSite($unit, $site);

        return response()->json([
            'condition_periods' => $unit->conditionPeriods()->latest('started_at')->get()->map(fn ($period) => [
                'id' => $period->id, 'condition' => $period->condition,
                'started_at' => $period->started_at?->toIso8601String(), 'ended_at' => $period->ended_at?->toIso8601String(),
                'note' => $period->note, 'created_by' => $period->created_by,
            ]),
            'repairs' => $unit->repairs()->get()->map(fn ($repair) => [
                'id' => $repair->id,
                'status_vhms' => $repair->status_vhms,
                'status' => $repair->status,
                'repair_started_at' => $repair->repair_started_at,
                'repair_ended_at' => $repair->repair_ended_at,
                'root_cause' => $repair->root_cause,
                'action' => $repair->action,
                'repair_note' => $repair->repair_note,
                'checking_by' => $repair->checking_by,
                'updated_by' => $repair->updated_by,
                'created_at' => $repair->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function complete(Request $request, string $site, VhmsNotUpdateCase $case): JsonResponse
    {
        $site = $this->authorizedSite($site);
        abort_unless($case->site === $site && $case->include_in_breakdown && ! $case->completed_at, 404);
        DB::transaction(function () use ($case, $request): void {
            $locked = VhmsNotUpdateCase::query()->lockForUpdate()->findOrFail($case->id);
            $completedAt = now();
            $locked->update(['workflow_status' => VhmsNotUpdateCase::COMPLETED, 'completed_at' => $completedAt]);
            VhmsUnitConditionPeriod::query()->where('list_unit_id', $locked->list_unit_id)->whereNull('ended_at')
                ->update(['ended_at' => $completedAt, 'updated_by' => $request->user()->name]);
        });
        return response()->json(['message' => 'Penanganan unit berhasil diselesaikan.']);
    }

    private function authorizedSite(string $site): string
    {
        $site = strtoupper(trim($site));
        $user = auth()->user();

        if ($user->role !== 'ict_developer' && strtoupper(trim((string) $user->site)) !== $site) {
            abort(403);
        }

        if (! ListUnit::query()->whereRaw('UPPER(TRIM(site)) = ?', [$site])->exists()) {
            abort(404);
        }

        return $site;
    }

    private function ensureUnitSite(ListUnit $unit, string $site): void
    {
        abort_unless(strtoupper(trim((string) $unit->site)) === $site, 404);
    }

    private function normalizeBooleanFilter(Request $request, string $key): void
    {
        if ($request->has($key)) {
            $request->merge([$key => $request->boolean($key)]);
        }
    }

    private function pagePayload($paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
