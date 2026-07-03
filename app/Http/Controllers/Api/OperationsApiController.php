<?php

namespace App\Http\Controllers\Api;

use App\Exports\MonitoringJobsExport;
use App\Http\Controllers\Controller;
use App\Models\DailyJob;
use App\Models\InvCctv;
use App\Models\InvComputer;
use App\Models\InvLaptop;
use App\Models\InvPrinter;
use App\Models\PerangkatBreakdown;
use App\Models\RootCauseCategories;
use App\Models\RootCauseProblem;
use App\Models\User;
use App\Support\Api\SiteContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;

class OperationsApiController extends Controller
{
    private const ROLE_ALL_WRITE   = ['ict_developer', 'ict_group_leader', 'ict_section_head', 'ict_admin', 'ict_technician'];
    // Source of truth: web DailyJobController/UnscheduleJobController gate
    // create, update AND destroy IDENTICALLY — `if (role != ict_developer &&
    // site != userSite) abort(403)` — i.e. the SAME role set (route middleware)
    // for all three write actions, only the site differs. The old per-action
    // lists (create/delete = [dev,GL] but update = [dev,GL,admin,tech]) diverged
    // from web and caused "create works but edit/delete 403". Unify all three to
    // the ICT write-role set; the developer-or-own-site gate stays in
    // authorizeSiteAccess().
    private const ROLE_CREATE_JOB  = self::ROLE_ALL_WRITE;
    private const ROLE_UPDATE_JOB  = self::ROLE_ALL_WRITE;
    private const ROLE_DELETE_JOB  = self::ROLE_ALL_WRITE;
    // Parity with the production Inertia page: DailyJobMonitorController@index
    // sets canApprove = in_array(role, ['ict_developer','ict_group_leader']) and
    // approveAll() authorizes the same two roles. Mirror it exactly here.
    private const ROLE_APPROVE_JOB = ['ict_group_leader', 'ict_developer'];

    // -------------------------------------------------------------------------
    // Job Assignment
    // -------------------------------------------------------------------------

    public function jobsIndex(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);
        $shift = $this->normalizeShift($request->input('shift'));

        $today = Carbon::today();
        $user = $request->user();
        $query = DailyJob::with('creator')
            ->when($request->filled(['start_date', 'end_date']), function ($builder) use ($request) {
                $builder->whereBetween('date', [$request->string('start_date'), $request->string('end_date')]);
            }, function ($builder) use ($today) {
                $builder->where(function ($query) use ($today) {
                    $query->whereDate('date', $today)
                        ->orWhere(function ($subQuery) use ($today) {
                            $subQuery->where('status', '!=', 'closed')
                                ->whereDate('date', '!=', $today);
                        });
                });
            })
            ->when($shift !== null, fn ($builder) => $builder->where('shift', $shift))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')))
            ->when(
                ! in_array($user->role, ['ict_developer', 'ict_group_leader', 'ict_ho'], true),
                fn ($builder) => $builder->whereJsonContains('crew', $user->id)
            )
            ->whereIn('category_job', ['assignment', 'support'])
            ->where('site', $site)
            ->orderByDesc('created_at');

        return response()->json([
            'data' => $query->paginate((int) $request->integer('per_page', 25)),
            'meta' => [
                'site' => $site,
                'users' => User::query()->select('id', 'name')->orderBy('name')->get(),
                'shift_options' => $this->shiftOptions(),
                'filters' => array_merge(
                    $request->only(['start_date', 'end_date', 'status']),
                    ['shift' => $shift]
                ),
                'can_create' => in_array($user->role, self::ROLE_CREATE_JOB, true),
                'can_approve' => in_array($user->role, self::ROLE_APPROVE_JOB, true),
            ],
        ]);
    }

    public function jobsMeta(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);

        return response()->json([
            'site' => $site,
            'users' => $this->siteCrewOptions($site),
            'shift_options' => $this->shiftOptions(),
            'categories' => RootCauseCategories::where('site_type', 'SITE')
                ->pluck('category_root_cause'),
        ]);
    }

    public function jobsStore(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRole($request, self::ROLE_CREATE_JOB);
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'shared.shift'    => ['nullable', 'string', 'in:SHIFT_1,SHIFT_2'],
            'shared.crew'     => ['nullable', 'array'],
            'shared.crew.*'   => ['nullable'],
            'shared.sarana'   => ['nullable', 'string'],
            'jobs'            => ['required', 'array', 'min:1'],
            'jobs.*.code'     => ['nullable', 'string'],
            'jobs.*.categoryJob' => ['nullable', 'string', 'in:assignment,support'],
            'jobs.*.job'      => ['required', 'string'],
            'jobs.*.date'     => ['nullable', 'date'],
            'jobs.*.due_date' => ['nullable', 'date'],
            'jobs.*.status'   => ['nullable', 'string', 'in:open,continue,closed'],
            'jobs.*.remark'   => ['nullable', 'string'],
        ]);

        foreach ($validated['jobs'] as $job) {
            DailyJob::create([
                'code'         => ! empty($job['code']) ? $job['code'] : $this->generateJobCode('JA'),
                'category_job' => $job['categoryJob'] ?? 'assignment',
                'description'  => $job['job'],
                'date'         => $job['date'] ?? now()->toDateString(),
                'due_date'     => $job['due_date'] ?? null,
                'status'       => $job['status'] ?? 'open',
                'remark'       => $job['remark'] ?? null,
                'shift'        => $this->normalizeShift(data_get($validated, 'shared.shift', 'SHIFT_1')),
                'crew'         => data_get($validated, 'shared.crew', []),
                'sarana'       => data_get($validated, 'shared.sarana'),
                'site'         => $site,
                'category'     => '-',
                'created_by'   => $request->user()->id,
            ]);
        }

        return response()->json(['message' => 'Jobs created successfully.'], 201);
    }

    public function jobsShow(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);

        $job = DailyJob::with(['creator', 'updater'])
            ->where('site', $site)
            ->where('code', $code)
            ->whereIn('category_job', ['assignment', 'support'])
            ->firstOrFail();

        return response()->json(['data' => $job]);
    }

    public function jobsUpdate(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRole($request, self::ROLE_UPDATE_JOB);
        $this->authorizeSiteAccess($request, $site);

        $job = DailyJob::where('site', $site)
            ->where('code', $code)
            ->whereIn('category_job', ['assignment', 'support'])
            ->firstOrFail();

        // NOTE: approval status must NOT gate CRUD. Web Inertia
        // (DailyJobController/UnscheduleJobController@update) only checks role
        // permission, never approval — approve affects export eligibility only.
        // Previously this aborted 403 when approval_status === 'approved'; that
        // restriction has been removed to match the web behavior exactly.

        $validated = $request->validate([
            'description'    => ['sometimes', 'string'],
            'remark'         => ['sometimes', 'nullable', 'string'],
            'due_date'       => ['sometimes', 'nullable', 'date'],
            'status'         => ['sometimes', 'string', 'in:open,continue,closed'],
            'category_job'   => ['sometimes', 'string', 'in:assignment,support'],
            'crew'           => ['sometimes', 'array'],
            'sarana'         => ['sometimes', 'nullable', 'string'],
            'shift'          => ['sometimes', 'string', 'in:SHIFT_1,SHIFT_2'],
            'action_taken'   => ['sometimes', 'nullable', 'string'],
            'start_progress' => ['sometimes', 'nullable', 'date'],
            'end_progress'   => ['sometimes', 'nullable', 'date'],
            'category'       => ['sometimes', 'nullable', 'string'],
            'root_cause'     => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('shift', $validated)) {
            $validated['shift'] = $this->normalizeShift($validated['shift']);
        }
        $validated['updated_by'] = $request->user()->id;
        $job->update($validated);

        return response()->json(['message' => 'Job updated successfully.', 'data' => $job->fresh()]);
    }

    public function jobsDestroy(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRole($request, self::ROLE_DELETE_JOB);
        $this->authorizeSiteAccess($request, $site);

        $job = DailyJob::where('site', $site)
            ->where('code', $code)
            ->whereIn('category_job', ['assignment', 'support'])
            ->firstOrFail();

        // NOTE: approval status must NOT gate CRUD. Web Inertia
        // (DailyJobController/UnscheduleJobController@destroy) only checks role
        // permission, never approval — approve affects export eligibility only.
        // Previously this aborted 403 when approval_status === 'approved'; that
        // restriction has been removed to match the web behavior exactly.

        $job->delete();

        return response()->json(['message' => 'Job deleted successfully.']);
    }

    // -------------------------------------------------------------------------
    // Approval
    //
    // Kolom approval daily_jobs = varchar `approval_status`
    //   NULL / ''        => belum di-approve
    //   'approved'       => sudah di-approve
    // Ini SATU-SATUNYA kolom approval yang dipakai aplikasi (dikonfirmasi dari
    // DB live new_itportalv4). Skema live TIDAK punya is_approved/approved_by/
    // approved_at, jadi kolom-kolom itu tidak boleh ditulis. Web Inertia
    // (DailyJobMonitorController) tidak punya aksi approve untuk daily jobs —
    // approve hanya tersedia di mobile API ini, dibatasi role Group Leader
    // (ROLE_APPROVE_JOB). Export tersedia setelah semua job pada filter aktif
    // memiliki approval_status = approved.
    // -------------------------------------------------------------------------

    private const APPROVAL_VALUE = 'approved';

    private function jobIsApproved(DailyJob $job): bool
    {
        return $job->approval_status === self::APPROVAL_VALUE;
    }

    public function approveJob(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRole($request, self::ROLE_APPROVE_JOB);
        $this->authorizeSiteAccess($request, $site);

        $job = DailyJob::where('site', $site)->where('code', $code)->firstOrFail();

        if ($this->jobIsApproved($job)) {
            return response()->json(['message' => 'Job sudah di-approve sebelumnya.']);
        }

        $job->update(['approval_status' => self::APPROVAL_VALUE]);

        return response()->json(['message' => 'Job berhasil di-approve.', 'data' => $job->fresh()]);
    }

    public function approveBatch(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRole($request, self::ROLE_APPROVE_JOB);
        $this->authorizeSiteAccess($request, $site);

        // Approve exactly the VISIBLE unapproved rows for the active filter (the
        // same set the Monitoring page shows and the gate evaluates), so after
        // approving, all visible rows are approved and the button flips to
        // "Export All Report". The previous version approved only `date = today`,
        // which never touched a filtered past range — so a range of unapproved
        // rows could never be approved from the UI.
        $shift = $this->normalizeShift($request->input('shift'));
        $ids = $this->monitoringScheduledQuery($request, $site, $shift)->get()
            ->concat($this->monitoringUnscheduledQuery($request, $site, $shift)->get())
            ->reject(fn ($job) => $this->jobIsApproved($job))
            ->pluck('id');

        $count = $ids->isEmpty()
            ? 0
            : DailyJob::whereIn('id', $ids)->update([
                'approval_status' => self::APPROVAL_VALUE,
                'updated_by'      => $request->user()->id,
                'updated_at'      => now(),
            ]);

        return response()->json(['message' => "Berhasil approve {$count} job"]);
    }

    // -------------------------------------------------------------------------
    // Monitoring Jobs
    // -------------------------------------------------------------------------

    public function monitoringIndex(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);
        $shift = $this->normalizeShift($request->input('shift'));
        $scheduledJobs   = $this->monitoringScheduledQuery($request, $site, $shift)->get();
        $unscheduledJobs = $this->monitoringUnscheduledQuery($request, $site, $shift)->get();

        // Approval gate — evaluated over the VISIBLE records (the exact rows the
        // page renders), NOT a separate today/operational-shift query. The old
        // allApprovedToday() looked at `date = today`, so with shift=All it
        // ignored the filtered range entirely: a range whose visible rows were
        // all approved still showed "Approve Job" (and vice-versa). A row counts
        // as approved iff approval_status === 'approved' — the SAME truth the
        // per-row green icon uses (is_approved is unreliable here: rows can be
        // approval_status='approved' while is_approved=0).
        $visible         = $scheduledJobs->concat($unscheduledJobs);
        $visibleCount    = $visible->count();
        $unapprovedCount = $visible->reject(fn ($job) => $this->jobIsApproved($job))->count();
        $approvedCount   = $visibleCount - $unapprovedCount;
        $hasJobs         = $visibleCount > 0;
        $allApproved     = $hasJobs && $unapprovedCount === 0;

        // Approve Job (batch) hanya untuk role approver; lihat approveBatch().
        $canApprove = in_array($request->user()->role, self::ROLE_APPROVE_JOB, true);

        // Single primary action (parity with MonitoringJobsDashboard.vue button
        // gate, now keyed on the visible set):
        //   Export All Report : all visible rows approved (v-if allApproved)
        //   Approve Job       : some row unapproved AND role may approve
        //   none              : no visible rows, or unapproved + cannot approve
        $primaryAction = ! $hasJobs
            ? 'none'
            : ($allApproved ? 'export' : ($canApprove ? 'approve' : 'none'));

        return response()->json([
            'data' => [
                'scheduledJobs'   => $scheduledJobs,
                'unscheduledJobs' => $unscheduledJobs,
            ],
            'meta' => [
                'site'          => $site,
                'all_approved'  => $allApproved,
                // Alias eksplisit sesuai kontrak metadata Monitoring Jobs.
                'is_approved'   => $allApproved,
                'has_jobs'      => $hasJobs,
                // Visibility debug counters (also drive nothing client-side, but
                // make the export/approve decision auditable from the response).
                'visible_count'    => $visibleCount,
                'approved_count'   => $approvedCount,
                'unapproved_count' => $unapprovedCount,
                'users'         => User::query()->select('id', 'name')->orderBy('name')->get(),
                'shift_options' => $this->shiftOptions(),
                'filters'       => array_merge(
                    $request->only(['start_date', 'end_date', 'status']),
                    ['shift' => $shift]
                ),
                'can_approve'      => $canApprove,
                'can_export'       => $allApproved,
                'export_ready'     => $allApproved,
                'export_available' => $allApproved,
                'primary_action'   => $primaryAction,
            ],
        ]);
    }

    public function monitoringExport(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);
        $shift = $this->normalizeShift($request->input('shift'));

        $validated = $request->validate([
            'format'     => ['nullable', 'string', 'in:csv,xlsx,pdf'],
            'scope'      => ['nullable', 'string', 'in:scheduled,unscheduled,all'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date'],
            'status'     => ['nullable', 'string'],
        ]);

        // Export gate uses the SAME predicate as the button (visible records all
        // approved), so whenever the UI shows "Export All Report" the download
        // succeeds, and an unapproved visible row blocks it.
        if (! $this->visibleApprovalState($request, $site, $shift)['all_approved']) {
            abort(403, 'Approve semua job yang tampil sebelum export.');
        }

        // Default = PDF (the Monitoring Jobs "Export All Report"). The standalone
        // Job Assignment / Job Un-Schedule pages still request the scope-based
        // CSV/XLSX spreadsheet via format=csv|xlsx; that path is preserved below.
        $format = strtolower((string) ($validated['format'] ?? 'pdf'));

        if (in_array($format, ['csv', 'xlsx'], true)) {
            $scope = strtolower((string) ($validated['scope'] ?? 'scheduled'));
            $rows  = collect();

            if (in_array($scope, ['scheduled', 'all'], true)) {
                $rows = $rows->concat($this->mapJobsForExport(
                    $this->monitoringScheduledQuery($request, $site, $shift)->get(),
                    'scheduled'
                ));
            }
            if (in_array($scope, ['unscheduled', 'all'], true)) {
                $rows = $rows->concat($this->mapJobsForExport(
                    $this->monitoringUnscheduledQuery($request, $site, $shift)->get(),
                    'unscheduled'
                ));
            }

            $filename = sprintf('monitoring-jobs-%s-%s.%s', strtolower($site), $scope, $format);

            return Excel::download(
                new MonitoringJobsExport($rows->values()),
                $filename,
                $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX
            );
        }

        // VERBATIM web parity with DailyJobMonitorControllerFinal@exportReportMonitoring:
        // the web "Export All Report" action renders the `dailyJobs.report-monitoring`
        // PDF (Job Assignment + Job Un-Schedule tables + diagram) and streams it as a
        // download — it is NOT csv/xlsx. We reuse the same Blade view, the same data
        // shape, and the same filename so the mobile download is byte-identical content.
        $scheduledJobs   = $this->monitoringScheduledQuery($request, $site, $shift)->get();
        $unscheduledJobs = $this->monitoringUnscheduledQuery($request, $site, $shift)->get();

        // crew_names — the Blade prints implode(', ', $job->crew_names).
        $users = User::all(['id', 'name'])->keyBy('id');
        foreach ($scheduledJobs as $job) {
            $job->crew_names = collect($job->crew ?? [])
                ->map(fn ($id) => $users[$id]->name ?? 'Unknown')->toArray();
        }
        foreach ($unscheduledJobs as $job) {
            $job->crew_names = collect($job->crew ?? [])
                ->map(fn ($id) => $users[$id]->name ?? 'Unknown')->toArray();
        }

        $jobAssignmentCount = $scheduledJobs->count();
        $unscheduleCount    = $unscheduledJobs->count();
        // The Blade diagram only draws Job Assignment + Job Un-Schedule bars; the
        // percentages are over the two rendered categories. (The web computes the
        // denominator over 4 categories incl. aduan/inspeksi, which the mobile
        // monitoringIndex does not yet expose — the rendered TABLES are identical;
        // only the diagram's denominator differs. Documented in the parity report.)
        $totalJobs = $jobAssignmentCount + $unscheduleCount;
        $jobAssignmentPct = $totalJobs > 0 ? round(($jobAssignmentCount / $totalJobs) * 100) : 0;
        $unschedulePct    = $totalJobs > 0 ? round(($unscheduleCount / $totalJobs) * 100) : 0;

        $data = [
            'date'  => now()->format('d M Y'),
            'shift' => $shift,
            'assignments' => $scheduledJobs,
            'unscheduled' => $unscheduledJobs,
            'aduanData' => collect(),
            'inspectionData' => [],
            'job_assignment_count' => $jobAssignmentCount,
            'unschedule_count' => $unscheduleCount,
            'aduan_count' => 0,
            'inspection_count' => 0,
            'job_assignment_percentage' => $jobAssignmentPct,
            'unschedule_percentage' => $unschedulePct,
            'aduan_percentage' => 0,
            'inspection_percentage' => 0,
        ];

        $pdf = Pdf::loadView('dailyJobs.report-monitoring', $data)->setPaper('A4', 'portrait');

        // Filename — VERBATIM web parity (Final@exportReportMonitoring):
        // "Rekap Monitoring Job {SITE} - {d F Y} - {SHIFT}.pdf".
        $startDate    = $request->input('start_date');
        $currentTime  = Carbon::now();
        if (! $shift) {
            if ($currentTime->format('H:i:s') >= '06:00:00' && $currentTime->format('H:i:s') <= '17:59:59') {
                $shiftLabel = 'SHIFT_1';
                $tanggalShift = $currentTime;
            } else {
                $shiftLabel = 'SHIFT_2';
                $tanggalShift = $currentTime->hour < 6 ? $currentTime->copy()->subDay() : $currentTime;
            }
        } else {
            $shiftLabel = $shift;
            $tanggalShift = $startDate ? Carbon::parse($startDate) : $currentTime;
        }

        $fileName = sprintf(
            'Rekap Monitoring Job %s - %s - %s.pdf',
            $site,
            $tanggalShift->translatedFormat('d F Y'),
            $shiftLabel
        );

        return $pdf->download($fileName);
    }

    // -------------------------------------------------------------------------
    // Job Unschedule (semua role bisa CRUD)
    // -------------------------------------------------------------------------

    public function unscheduleIndex(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);
        $shift = $this->normalizeShift($request->input('shift'));

        $today = Carbon::today();
        $user  = $request->user();

        $query = DailyJob::with('creator')
            ->where('category_job', 'unschedule')
            ->when($request->filled(['start_date', 'end_date']), function ($builder) use ($request) {
                $builder->whereBetween('date', [$request->string('start_date'), $request->string('end_date')]);
            }, function ($builder) use ($today) {
                $builder->whereDate('date', $today);
            })
            ->when($shift !== null, fn ($builder) => $builder->where('shift', $shift))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')))
            ->when(
                ! in_array($user->role, ['ict_developer', 'ict_group_leader', 'ict_ho'], true),
                // Non-privileged roles see a row when they are assigned to it
                // (in `crew`) OR when they created it. Without the created_by
                // branch a creator who assigns the job to a different crew could
                // never see their own unscheduled job (bug: creator invisible to
                // self while visible to higher roles).
                fn ($builder) => $builder->where(function ($sub) use ($user) {
                    $sub->whereJsonContains('crew', $user->id)
                        ->orWhere('created_by', $user->id);
                })
            )
            ->where('site', $site)
            ->orderByDesc('created_at');

        return response()->json([
            'data' => $query->paginate((int) $request->integer('per_page', 25)),
            'meta' => [
                'site'          => $site,
                'users'         => User::query()->select('id', 'name')->orderBy('name')->get(),
                'shift_options' => $this->shiftOptions(),
                'filters'       => array_merge(
                    $request->only(['start_date', 'end_date', 'status']),
                    ['shift' => $shift]
                ),
            ],
        ]);
    }

    public function unscheduleMeta(Request $request)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);

        return response()->json([
            'site'          => $site,
            'users'         => $this->siteCrewOptions($site),
            'shift_options' => $this->shiftOptions(),
            'categories'    => RootCauseCategories::where('site_type', 'SITE')->pluck('category_root_cause'),
            // Web UnscheduleJobController@create also passes `categoriesBd` — the
            // breakdown-enabled categories used by the "Enable Category and
            // Inventory Number" section (root_cause_categories where breakdown=1).
            'categoriesBd'  => RootCauseCategories::where('breakdown', 1)
                ->select('id', 'category_root_cause')
                ->get(),
        ]);
    }

    public function unscheduleProblems(Request $request)
    {
        $request->validate(['category' => ['required', 'string']]);

        return response()->json([
            'data' => RootCauseProblem::where('kategori_name', $request->string('category'))
                ->pluck('root_cause_problem'),
        ]);
    }

    public function unscheduleStore(Request $request)
    {
        $site = $this->resolveSite($request);
        // Semua role bisa buat unschedule job
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'shared.shift'            => ['nullable', 'string', 'in:SHIFT_1,SHIFT_2'],
            'shared.crew'             => ['nullable', 'array'],
            'shared.crew.*'           => ['nullable'],
            'jobs'                    => ['required', 'array', 'min:1'],
            'jobs.*.code'             => ['nullable', 'string'],
            'jobs.*.job'              => ['required', 'string'],
            'jobs.*.issue'            => ['nullable', 'string'],
            'jobs.*.action_taken'     => ['nullable', 'string'],
            'jobs.*.date'             => ['nullable', 'date'],
            'jobs.*.status'           => ['nullable', 'string', 'in:open,continue,closed'],
            'jobs.*.remark'           => ['nullable', 'string'],
            'jobs.*.start_progress'   => ['nullable', 'date'],
            'jobs.*.end_progress'     => ['nullable', 'date'],
            'jobs.*.category'         => ['required', 'string'],
            'jobs.*.root_cause_problem' => ['nullable', 'string'],
            // "Enable Category and Inventory Number" section (web parity):
            'jobs.*.inventory'          => ['nullable', 'string'],
            'jobs.*.category_breakdown' => ['nullable', 'string'],
        ]);

        foreach ($validated['jobs'] as $job) {
            $code = ! empty($job['code']) ? $job['code'] : $this->generateJobCode('UJ');

            DailyJob::create([
                'code'          => $code,
                'category_job'  => 'unschedule',
                'description'   => $job['job'],
                'issue'         => $job['issue'] ?? null,
                'action_taken'  => $job['action_taken'] ?? null,
                'date'          => $job['date'] ?? now()->toDateString(),
                'due_date'      => null,
                'status'        => $job['status'] ?? 'open',
                'remark'        => $job['remark'] ?? null,
                'shift'         => $this->normalizeShift(data_get($validated, 'shared.shift', 'SHIFT_1')),
                'crew'          => data_get($validated, 'shared.crew', []),
                'sarana'        => null,
                'start_progress' => $job['start_progress'] ?? null,
                'end_progress'  => $job['end_progress'] ?? null,
                'site'          => $site,
                'category'      => $job['category'],
                'root_cause'    => $job['root_cause_problem'] ?? null,
                'created_by'    => $request->user()->id,
            ]);

            // Mirror UnscheduleJobController@store: when an inventory number was
            // chosen (toggle ON), record a PerangkatBreakdown, resolving the
            // device from the matching inventory table by its code.
            if (! empty($job['inventory'])) {
                $this->createBreakdownRecord($request, $site, $code, $job);
            }
        }

        return response()->json(['message' => 'Unscheduled jobs created successfully.'], 201);
    }

    private function createBreakdownRecord(Request $request, string $site, string $code, array $job): void
    {
        $now = Carbon::now();
        $categoryInput = strtoupper((string) ($job['category_breakdown'] ?? ''));

        $deviceName     = 'Unknown Device';
        $deviceLocation = '';
        $idPerangkat    = 'unknown ID';

        $inv = match ($categoryInput) {
            'LAPTOP'   => InvLaptop::where('laptop_code', $job['inventory'])->first(),
            'COMPUTER' => InvComputer::where('computer_code', $job['inventory'])->first(),
            'PRINTER'  => InvPrinter::where('printer_code', $job['inventory'])->first(),
            'CCTV'     => InvCctv::where('cctv_code', $job['inventory'])->first(),
            default    => null,
        };

        if ($inv) {
            $idPerangkat    = $inv->id;
            $deviceLocation = $inv->location ?? '';
            $deviceName = match ($categoryInput) {
                'LAPTOP'   => $inv->laptop_name,
                'COMPUTER' => $inv->computer_name,
                'PRINTER'  => $inv->printer_brand,
                'CCTV'     => $inv->cctv_brand,
                default    => $deviceName,
            };
        }

        PerangkatBreakdown::create([
            'id_report'           => $code,
            'id_perangkat'        => $idPerangkat,
            'inventory_number'    => $job['inventory'],
            'device_name'         => $deviceName,
            'category_breakdown'  => $categoryInput !== '' ? $categoryInput : null,
            'device_category'     => $job['category'] ?? null,
            'pic'                 => $request->user()->name,
            'start_time'          => $job['start_progress'] ?? null,
            'end_time'            => $job['end_progress'] ?? null,
            'created_date'        => $now->toDateString(),
            'month'               => $now->month,
            'year'                => $now->year,
            'root_cause'          => $job['root_cause_problem'] ?? null,
            'root_cause_category' => $job['category'] ?? null,
            'location'            => $deviceLocation,
            'status'              => strtoupper((string) ($job['status'] ?? 'open')),
            'site'                => $request->user()->site,
        ]);
    }

    public function unscheduleShow(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeRead($request, $site);

        $job = DailyJob::with(['creator', 'updater'])
            ->where('site', $site)
            ->where('code', $code)
            ->where('category_job', 'unschedule')
            ->firstOrFail();

        return response()->json(['data' => $job]);
    }

    public function unscheduleUpdate(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeSiteAccess($request, $site);

        $job = DailyJob::where('site', $site)
            ->where('code', $code)
            ->where('category_job', 'unschedule')
            ->firstOrFail();

        $validated = $request->validate([
            'description'    => ['sometimes', 'string'],
            'issue'          => ['sometimes', 'nullable', 'string'],
            'action_taken'   => ['sometimes', 'nullable', 'string'],
            'remark'         => ['sometimes', 'nullable', 'string'],
            'status'         => ['sometimes', 'string', 'in:open,continue,closed'],
            'crew'           => ['sometimes', 'array'],
            'shift'          => ['sometimes', 'string', 'in:SHIFT_1,SHIFT_2'],
            'start_progress' => ['sometimes', 'nullable', 'date'],
            'end_progress'   => ['sometimes', 'nullable', 'date'],
            'category'       => ['sometimes', 'string'],
            'root_cause'     => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('shift', $validated)) {
            $validated['shift'] = $this->normalizeShift($validated['shift']);
        }
        $validated['updated_by'] = $request->user()->id;
        $job->update($validated);

        return response()->json(['message' => 'Unscheduled job updated successfully.', 'data' => $job->fresh()]);
    }

    public function unscheduleDestroy(Request $request, string $code)
    {
        $site = $this->resolveSite($request);
        $this->authorizeSiteAccess($request, $site);

        $job = DailyJob::where('site', $site)
            ->where('code', $code)
            ->where('category_job', 'unschedule')
            ->firstOrFail();

        $job->delete();

        return response()->json(['message' => 'Unscheduled job deleted successfully.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveSite(Request $request): string
    {
        if (! SiteContext::canAccessAnySite($request)) {
            return strtoupper((string) ($request->user()?->site ?? 'HO'));
        }

        return strtoupper((string) $request->input('site', $request->user()?->site ?? 'HO'));
    }

    private function authorizeRead(Request $request, string $site): void
    {
        $user = $request->user();

        if (! SiteContext::canAccessAnySite($request) && strtoupper((string) $user->site) !== $site) {
            abort(403, 'You dont have permission to access this page.');
        }
    }

    // Hanya cek role, tanpa cek site
    private function authorizeRole(Request $request, array $roles): void
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            abort(403, 'Role kamu tidak memiliki akses untuk aksi ini.');
        }
    }

    // Hanya cek kesesuaian site
    private function authorizeSiteAccess(Request $request, string $site): void
    {
        $user = $request->user();

        if (! SiteContext::canAccessAnySite($request) && strtoupper((string) $user->site) !== $site) {
            abort(403, 'You dont have permission to access this page.');
        }
    }

    // -------------------------------------------------------------------------
    // Monitoring query builders — VERBATIM parity with the web source of truth
    // DailyJobMonitorController@index ($scheduledJobs / $unscheduledJobs). Proven
    // row-for-row equal via the evidence harness in
    // docs/MONITORING_JOBS_EVIDENCE_BASED_FIX.md. Do NOT reintroduce the old
    // updated_at-based logic — it produced a different record set than the web.
    // -------------------------------------------------------------------------

    private function monitoringScheduledQuery(Request $request, string $site, ?string $shift)
    {
        $today = Carbon::today();
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $status = $request->input('status');

        return DailyJob::with('creator')
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('date', [$startDate, $endDate]);
            }, function ($query) use ($today) {
                $query->where('status', '!=', 'closed')
                    ->where(function ($q) use ($today) {
                        $q->whereNull('due_date')
                            ->orWhereDate('due_date', '>=', $today);
                    });
            })
            ->when($shift, fn ($query) => $query->where('shift', $shift))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->where('category_job', '!=', 'unschedule')
            ->where('site', $site)
            ->orderBy('date', 'desc');
    }

    private function monitoringUnscheduledQuery(Request $request, string $site, ?string $shift)
    {
        [$operationalDate, $operationalShift] = $this->operationalWindow($request, $shift);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $status = $request->input('status');

        // VERBATIM parity with DailyJobMonitorControllerFinal@index $unscheduledJobs:
        // unschedule rows are filtered by the START_PROGRESS instant inside the
        // shift's operational window — NOT by the `date` column and NOT by a plain
        // where('shift', ...). The shift is baked into the start_progress window:
        //   SHIFT_1 => 06:00:00 .. 17:59:59 (same day)
        //   SHIFT_2 => 18:00:00 .. 05:59:59 (next day)
        // With an explicit start_date+end_date the bounds span that range; with no
        // range we fall back to the operational date/shift. ordered by start_progress.
        return DailyJob::with('creator')
            ->where('category_job', 'unschedule')
            ->when(
                $startDate && $endDate,
                function ($query) use ($startDate, $endDate, $shift) {
                    if ($shift === 'SHIFT_1') {
                        $query->whereBetween('start_progress', [
                            $startDate . ' 06:00:00',
                            $endDate . ' 17:59:59',
                        ]);
                    } elseif ($shift === 'SHIFT_2') {
                        $endDateNext = Carbon::parse($endDate)->addDay()->toDateString();
                        $query->whereBetween('start_progress', [
                            $startDate . ' 18:00:00',
                            $endDateNext . ' 05:59:59',
                        ]);
                    } else {
                        $query->whereBetween('start_progress', [
                            $startDate . ' 00:00:00',
                            $endDate . ' 23:59:59',
                        ]);
                    }
                },
                function ($query) use ($operationalDate, $operationalShift) {
                    if ($operationalShift === 'SHIFT_1') {
                        $query->whereBetween('start_progress', [
                            $operationalDate . ' 06:00:00',
                            $operationalDate . ' 17:59:59',
                        ]);
                    } else {
                        $nextDate = Carbon::parse($operationalDate)->addDay()->toDateString();
                        $query->whereBetween('start_progress', [
                            $operationalDate . ' 18:00:00',
                            $nextDate . ' 05:59:59',
                        ]);
                    }
                }
            )
            ->when($status, fn ($query) => $query->where('status', $status))
            ->where('site', $site)
            ->orderBy('start_progress', 'desc');
    }

    /**
     * Operational date/shift window — verbatim parity with the web index()
     * computation. With an explicit start_date + shift it honours them; otherwise
     * it derives the current operational shift from the clock (06:00–17:59 =>
     * SHIFT_1, else SHIFT_2 with the date rolled back before 06:00).
     *
     * @return array{0:string,1:string} [operationalDate, operationalShift]
     */
    private function operationalWindow(Request $request, ?string $shift): array
    {
        $startDate = $request->input('start_date');

        if ($startDate && $shift) {
            return [$startDate, $shift];
        }

        $now = now();

        if ($now->hour >= 6 && $now->hour < 18) {
            return [$now->toDateString(), 'SHIFT_1'];
        }

        $operationalDate = $now->hour < 6
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();

        return [$operationalDate, 'SHIFT_2'];
    }

    /**
     * Approval state of the VISIBLE Monitoring records for the active filter —
     * the single source of truth for the Approve/Export gate. Evaluates the
     * exact scheduled + unscheduled rows the page renders (NOT a today-based
     * query), so the button always agrees with what the user sees. A row is
     * approved iff approval_status === 'approved' (same as the green icon).
     *
     * @return array{count:int,approved:int,unapproved:int,all_approved:bool}
     */
    private function visibleApprovalState(Request $request, string $site, ?string $shift): array
    {
        $visible = $this->monitoringScheduledQuery($request, $site, $shift)->get()
            ->concat($this->monitoringUnscheduledQuery($request, $site, $shift)->get());

        $count      = $visible->count();
        $unapproved = $visible->reject(fn ($job) => $this->jobIsApproved($job))->count();

        return [
            'count'        => $count,
            'approved'     => $count - $unapproved,
            'unapproved'   => $unapproved,
            'all_approved' => $count > 0 && $unapproved === 0,
        ];
    }

    private function mapJobsForExport($jobs, string $jobType)
    {
        $users = User::query()->select('id', 'name')->get()->keyBy('id');

        return $jobs->map(function (DailyJob $job) use ($jobType, $users) {
            $crewIds   = collect($job->crew ?? [])->filter(fn ($id) => $id !== null)->values();
            $crewNames = $crewIds->map(fn ($id) => $users->get($id)?->name ?? (string) $id)->values();

            return [
                'job_type'     => $jobType,
                'code'         => $job->code,
                'site'         => $job->site,
                'category_job' => $job->category_job,
                'description'  => $job->description,
                'category'     => $job->category,
                'shift'        => $job->shift,
                'status'       => $job->status,
                'urgency'      => $job->urgency,
                'date'         => optional($job->date)->format('Y-m-d'),
                'due_date'     => $job->due_date,
                'start_progress' => optional($job->start_progress)->format('Y-m-d H:i:s'),
                'end_progress'   => optional($job->end_progress)->format('Y-m-d H:i:s'),
                'issue'        => $job->issue,
                'root_cause'   => $job->root_cause,
                'action_taken' => $job->action_taken,
                'remark'       => $job->remark,
                'sarana'       => $job->sarana,
                'crew_ids'     => $crewIds->implode(', '),
                'crew_names'   => $crewNames->implode(', '),
                'approval_status' => $this->jobIsApproved($job) ? 'Approved' : 'Belum',
                'creator_name' => $job->creator?->name,
                'created_at'   => optional($job->created_at)->format('Y-m-d H:i:s'),
                'updated_at'   => optional($job->updated_at)->format('Y-m-d H:i:s'),
            ];
        });
    }

    private function siteCrewOptions(string $site)
    {
        return User::where('site', $site)
            ->where('ict_group', 'Y')
            ->get()
            ->map(fn ($user) => [
                'id'    => $user->id,
                'name'  => $user->name,
                'label' => "{$user->name} - {$user->site} - {$user->nrp}",
            ])
            ->values();
    }

    /**
     * Mirror the Inertia web client-side generator:
     *   CreateJobAssign.vue            => `JA` + 6 lowercase-alphanumeric chars
     *   UnscheduleJobs/CreateJobAssign => `UJ` + 6 lowercase-alphanumeric chars
     * The web builds this in the browser and submits it; mobile omits it, so the
     * API must generate the identical format (the `code` is the route key for
     * show/update/delete — a null code would make the row unreachable).
     */
    private function generateJobCode(string $prefix): string
    {
        $chars  = '0123456789abcdefghijklmnopqrstuvwxyz';
        $result = '';
        for ($i = 0; $i < 6; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $prefix . $result;
    }

    private function normalizeShift(null|string $shift): ?string
    {
        if ($shift === null || $shift === '') {
            return null;
        }

        // AUTHORITATIVE: production daily_jobs.shift stores 'SHIFT_1'/'SHIFT_2'
        // (web write-path DailyJobController::store:157 & UnscheduleJobController::
        // store:169 default 'SHIFT_1'; validation in:SHIFT_1,SHIFT_2). The filters
        // run where('shift', $request->shift) with NO mapping, so the value sent
        // MUST be the stored token. Any stray legacy 'pagi'/'malam' row is folded
        // onto the canonical token; this NEVER emits pagi/malam.
        return match (strtoupper((string) $shift)) {
            'SHIFT_1', 'PAGI'  => 'SHIFT_1',
            'SHIFT_2', 'MALAM' => 'SHIFT_2',
            default => strtoupper((string) $shift),
        };
    }

    private function shiftOptions(): array
    {
        return [
            ['label' => 'Shift 1', 'value' => 'SHIFT_1'],
            ['label' => 'Shift 2', 'value' => 'SHIFT_2'],
        ];
    }
}
