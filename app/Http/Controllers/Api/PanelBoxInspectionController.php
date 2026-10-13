<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InspeksiPanelBoxNetwork;
use App\Models\PicaInspeksi;
use App\Services\InspectionAttachedDeviceProcessor;
use App\Support\Api\SiteContext;
use App\Support\InspectionPicaNumber;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PanelBoxInspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site' => ['nullable', 'string', 'max:25'], 'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'inspection_status' => ['nullable', 'string', 'max:255'], 'status_approval' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! SiteContext::canAccessAnySite($request)) {
            $filters['site'] = SiteContext::resolve($request);
        }
        $query = InspeksiPanelBoxNetwork::query()->with(['panelBox', 'devices']);
        if (! SiteContext::canAccessAnySite($request)) {
            SiteContext::apply($query, 'site', SiteContext::resolve($request));
        }
        $data = $query
            ->when($filters['site'] ?? null, fn ($q, $site) => $q->where('site', strtoupper($site)))
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->where('month', $month))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['inspection_status'] ?? null, fn ($q, $status) => $q->where('inspection_status', $status))
            ->when($filters['status_approval'] ?? null, fn ($q, $status) => $q->where('status_approval', $status))
            ->latest('inspection_at')->paginate($filters['per_page'] ?? 15);

        return response()->json($data);
    }

    public function show(Request $request, string $inspection): JsonResponse
    {
        $query = InspeksiPanelBoxNetwork::with(['panelBox', 'devices']);
        if (! SiteContext::canAccessAnySite($request)) {
            SiteContext::apply($query, 'site', SiteContext::resolve($request));
        }
        return response()->json(['data' => $query->findOrFail($inspection)]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate([
            'cleanliness' => ['required', 'string', 'max:255'], 'conditions' => ['required', 'string', 'max:255'],
            'cable_arrangement' => ['required', 'string', 'max:255'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'findings' => ['nullable', 'string'], 'findings_action' => ['nullable', 'string'],
            'findings_status' => ['nullable', 'string', 'max:255'], 'due_date' => ['nullable', 'date'], 'remarks' => ['nullable', 'string'],
            'findings_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'action_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            ...$this->deviceRules(),
        ]);
        $record = InspeksiPanelBoxNetwork::with('panelBox')->findOrFail($inspection);
        $this->assertCanProcess($request, $record->site);

        $photos = new TowerInspectionPhotoStorage;
        try {
            DB::transaction(function () use ($request, $record, $validated, $photos): void {
                $findingsImage = isset($validated['findings_image']) ? $photos->store($validated['findings_image']) : $record->findings_image;
                $actionImage = isset($validated['action_image']) ? $photos->store($validated['action_image']) : $record->action_image;
                $record->panelBox?->update(['status' => $validated['inventory_status'], 'inspection_remark' => $validated['remarks'] ?? null]);
                $hasFindings = filled($validated['findings'] ?? null);
                $picaNumber = $hasFindings ? ($record->pica_number ?: $this->nextPicaNumber($record->site)) : null;
                $record->update([
                    'cleanliness' => $validated['cleanliness'], 'conditions' => $validated['conditions'], 'cable_arrangement' => $validated['cable_arrangement'],
                    'findings' => $hasFindings ? $validated['findings'] : null, 'findings_action' => $hasFindings ? ($validated['findings_action'] ?? null) : null,
                    'findings_status' => $hasFindings ? ($validated['findings_status'] ?? null) : null, 'due_date' => $hasFindings ? ($validated['due_date'] ?? null) : null,
                    'remarks' => $validated['remarks'] ?? null, 'pica_number' => $picaNumber, 'inspection_status' => 'Y', 'inspection_at' => now(), 'inspection_by' => $request->user()->name,
                    'findings_image' => $findingsImage, 'action_image' => $actionImage,
                ]);
                if (array_key_exists('devices', $validated)) {
                    app(InspectionAttachedDeviceProcessor::class)->replace($record, 'panel_box', $validated['devices'], $request->user(), $photos);
                }
                $pica = PicaInspeksi::where('inspeksi_id', $record->id)->first();
                if (! $hasFindings) {
                    $pica?->delete();

                    return;
                }
                $picaData = ['pica_number' => $picaNumber, 'temuan' => $validated['findings'], 'foto_temuan' => $findingsImage, 'tindakan' => $validated['findings_action'] ?? null, 'foto_tindakan' => $actionImage, 'status_pica' => $validated['findings_status'] ?? null, 'due_date' => $validated['due_date'] ?? null, 'remark' => $validated['remarks'] ?? null, 'site' => $record->site];
                $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $record->id]);
            });
        } catch (\Throwable $exception) {
            $photos->rollback();
            throw $exception;
        }

        return response()->json(['data' => InspeksiPanelBoxNetwork::with(['panelBox', 'devices'])->findOrFail($inspection)]);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate(['approval_type' => ['required', Rule::in(['accept', 'reject'])]]);
        $record = InspeksiPanelBoxNetwork::findOrFail($inspection);
        $this->assertCanApprove($request, $record->site);
        abort_unless($record->inspection_status === 'Y', 422, 'Data ini belum diinspeksi.');
        $record->update(['approved_by' => $request->user()->name, 'status_approval' => $validated['approval_type'] === 'accept' ? 'approved' : 'reject']);

        return response()->json(['data' => InspeksiPanelBoxNetwork::with(['panelBox', 'devices'])->findOrFail($inspection)]);
    }

    private function assertCanProcess(Request $request, string $site): void
    {
        abort_unless(in_array($request->user()->role, ['ict_technician', 'ict_group_leader'], true) && strtoupper((string) $request->user()->site) === strtoupper($site), 403, 'Anda hanya dapat memproses inspeksi site sendiri.');
    }

    private function assertCanApprove(Request $request, string $site): void
    {
        abort_unless($request->user()->role === 'ict_group_leader' && strtoupper((string) $request->user()->site) === strtoupper($site), 403, 'Group Leader hanya dapat melakukan approval untuk site sendiri.');
    }

    private function nextPicaNumber(string $site): string
    {
        return InspectionPicaNumber::next($site, 'PBN');
    }

    private function deviceRules(): array
    {
        return [
            'devices' => ['nullable', 'array'], 'devices.*.type' => ['required', Rule::in(['ACCESS_POINT', 'SWITCH', 'WIRELLESS'])],
            'devices.*.inspection_id' => ['required', 'uuid'], 'devices.*.inventory_number' => ['nullable', 'string', 'max:255'],
            'devices.*.device_status' => ['nullable', 'string', 'max:255'], 'devices.*.condition' => ['nullable', 'string', 'max:255'],
            'devices.*.inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'devices.*.remarks' => ['nullable', 'string'], 'devices.*.has_findings' => ['nullable', 'boolean'],
            'devices.*.findings' => ['nullable', 'string'], 'devices.*.corrective_action' => ['nullable', 'string'],
            'devices.*.finding_status' => ['nullable', 'string', 'max:255'], 'devices.*.target_completion_date' => ['nullable', 'date'],
            'devices.*.findings_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'devices.*.corrective_action_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }
}
