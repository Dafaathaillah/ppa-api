<?php

namespace App\Http\Controllers;

use App\Models\ListUnit;
use App\Http\Requests\UnitVhmsTableRequest;
use App\Queries\UnitVhmsTableQuery;
use App\Services\VhmsApiUnitSynchronizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Imports\UnitVhmsEvidenceImport;
use App\Exports\UnitVhmsEvidenceExport;
use App\Exports\UnitVhmsEvidenceTemplateExport;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class UnitVhmsController extends Controller
{
    private const SITE_SELECTOR_NRP = '23002073';

    public function importEvidence(Request $request, string $site)
    {
        $site = $this->authorizeSite($site);
        $request->validate(['file'=>'required|file|mimes:xlsx,xls|max:20480']);
        $import = new UnitVhmsEvidenceImport($site, auth()->user());
        Excel::import($import, $request->file('file'));
        return response()->json(['message'=>'Import evidence selesai.','updated'=>$import->updated,'skipped'=>$import->skipped,'errors'=>array_slice($import->errors,0,50)]);
    }

    public function exportEvidence(string $site) { $site=$this->authorizeSite($site); return Excel::download(new UnitVhmsEvidenceExport($site), "Data-Evidence-VHMS-{$site}.xlsx"); }
    public function evidenceTemplate(string $site) { $site=$this->authorizeSite($site); return Excel::download(new UnitVhmsEvidenceTemplateExport, "Format-Import-Evidence-VHMS-{$site}.xlsx"); }
    public function index($site)
    {
        $user = auth()->user();
        $site = $this->authorizeSite($site);
        $role = $user->role;
        return Inertia::render('UnitVhms/UnitVhms', [
            'site' => $site,
            'role' => $role,
            'availableSites' => (string) $user->nrp === self::SITE_SELECTOR_NRP
                ? VhmsApiUnitSynchronizer::SITES
                : [],
        ]);
    }

    public function data(UnitVhmsTableRequest $request, string $site, VhmsApiUnitSynchronizer $sync, UnitVhmsTableQuery $table)
    {
        $site = $this->authorizeSite($site);
        $params = $request->validated();
        if ((bool) ($params['refresh'] ?? false)) {
            $syncMeta = $sync->sync($site, true);
        } else {
            $syncMeta = $sync->currentMetadata($site);
        }
        return response()->json([
            ...$table->paginate($site, $params)->toArray(),
            'summary' => $table->summary($site),
            'sync' => $syncMeta,
        ]);
    }

    public function payload(string $site, VhmsApiUnitSynchronizer $sync)
    {
        $site = $this->authorizeSite($site);

        try {
            return response()->json([
                'site' => $site,
                'endpoint' => $sync->endpoint($site),
                'fetched_at' => now()->toIso8601String(),
                'payload' => $sync->rawPayload($site),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Payload API VHMS tidak dapat diambil. Silakan coba kembali.',
            ], 502);
        }
    }

    public function create(string $site)
    {
        $user = auth()->user();
        $site = $this->authorizeSite($site);

        return Inertia::render(
            'UnitVhms/UnitVhmsCreate',
            [
                'site' => $site,
            ]
        );
    }

    public function store(Request $request, $site)
    {
        $site = $this->authorizeSite($site);

        ListUnit::create([
            'type' => $request->type,
            'model' => $request->model,
            'sn' => $request->sn,
            'cn' => $request->cn,
            'site' => $site,
            'status' => $request->status,
            'tipe_wirelless' => $request->tipe_wirelless,
            'ip_wirelless' => $request->ip_wirelless,
            'mac_wirelless' => $request->mac_wirelless,
            'remark' => $request->remark,
            'last_update_at' => Carbon::now(),
            'last_update_by' => auth()->user()->name ?? 'System',
        ]);

        return redirect()->route('unit-vhms.index', [
            'site' => $site
        ]);
    }

    public function edit(string $site, string $id)
    {
        $site = $this->authorizeSite($site);
        $unit = $this->unitForSite($site, $id);

        return Inertia::render(
            'UnitVhms/UnitVhmsEdit',
            [
                'site' => $site,
                'unit' => $unit,
            ]
        );
    }

    public function update(Request $request, string $site, string $id)
    {
        $site = $this->authorizeSite($site);
        $unit = $this->unitForSite($site, $id);

        $unit->update([
            // Since type, model, sn, cn are disabled, we might not receive them or shouldn't update them
            // But we can just use request data if available
            'status' => $request->status,
            'tipe_wirelless' => $request->tipe_wirelless,
            'ip_wirelless' => $request->ip_wirelless,
            'mac_wirelless' => $request->mac_wirelless,
            'remark' => $request->remark,
            'last_update_at' => Carbon::now(),
            'last_update_by' => auth()->user()->name ?? 'System',
        ]);

        return redirect()->route(
            'unit-vhms.index',
            [
                'site' => $site
            ]
        );
    }

    public function detail(string $site, string $id)
    {
        $site = $this->authorizeSite($site);
        $unit = $this->unitForSite($site, $id);

        return Inertia::render(
            'UnitVhms/UnitVhmsDetail',
            [
                'site' => $site,
                'unit' => $unit,
            ]
        );
    }

    public function destroy(string $site, string $id)
    {
        $site = $this->authorizeSite($site);
        if (auth()->user()->role !== 'ict_developer') {
            abort(403);
        }
        $unit = $this->unitForSite($site, $id);
        $unit->delete();

        return redirect()->back();
    }

    private function authorizeSite(string $site): string
    {
        $site = strtoupper(trim($site));
        abort_unless(in_array($site, VhmsApiUnitSynchronizer::SITES, true), 404);
        $user = auth()->user();
        abort_unless($user, 403);
        if ($user->role !== 'ict_developer' && (string) $user->nrp !== self::SITE_SELECTOR_NRP) {
            abort_unless(strtoupper(trim((string) $user->site)) === $site, 403);
        }
        return $site;
    }

    private function unitForSite(string $site, string $id): ListUnit
    {
        return ListUnit::query()
            ->where('site_normalized', strtolower($site))
            ->findOrFail($id);
    }
}
