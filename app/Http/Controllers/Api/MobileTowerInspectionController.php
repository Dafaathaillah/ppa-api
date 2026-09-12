<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InspeksiMobileTower;
use App\Models\PicaInspeksi;
use App\Services\InspectionAttachedDeviceProcessor;
use App\Support\InspectionPicaNumber;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileTowerInspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'inspection_status' => ['nullable', 'string', 'max:255'],
            'status_approval' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $data = InspeksiMobileTower::query()->with(['mt', 'devices'])
            ->when($filters['site'] ?? null, fn ($q, $site) => $q->where('site', strtoupper($site)))
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->where('month', $month))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['inspection_status'] ?? null, fn ($q, $status) => $q->where('inspection_status', $status))
            ->when($filters['status_approval'] ?? null, fn ($q, $status) => $q->where('status_approval', $status))
            ->latest('inspection_at')->paginate($filters['per_page'] ?? 15);

        return response()->json($data);
    }

    public function show(string $inspection): JsonResponse
    {
        return response()->json(['data' => InspeksiMobileTower::with(['mt', 'devices'])->findOrFail($inspection)]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate([
            'condition' => ['required', 'string', 'max:255'],
            'worthiness' => ['required', 'string', 'max:255'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'location' => ['required', 'string', 'max:255'],
            'detail_location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'checklist_results_list' => ['required', 'array'],
            'list_results_remark' => ['nullable', 'array'],
            'findings' => ['nullable', 'array'],
            'findings.*' => ['nullable', 'string'],
            'findings_status' => ['nullable', 'array'],
            'findings_action' => ['nullable', 'array'],
            'due_date' => ['nullable', 'date'],
            'pic' => ['nullable', 'string', 'max:255'],
            'crew' => ['nullable', 'array'],
            'crew.*' => ['nullable', 'string', 'max:255'],
            'list_of_needs' => ['nullable', 'array'],
            'list_of_needs.*' => ['nullable', 'string', 'max:255'],
            'inspection_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'findings_images' => ['nullable', 'array'],
            'findings_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'action_images' => ['nullable', 'array'],
            'action_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            ...$this->deviceRules(),
        ]);
        $record = InspeksiMobileTower::with('mt')->findOrFail($inspection);
        $this->assertCanProcess($request, $record->site);

        $photos = new TowerInspectionPhotoStorage;
        try {
            DB::transaction(function () use ($request, $record, $validated, $photos): void {
                $inspectionImage = isset($validated['inspection_image']) ? $photos->store($validated['inspection_image']) : $record->inspection_image;
                $findingsImages = array_map(fn ($photo) => $photos->store($photo), array_filter($validated['findings_images'] ?? []));
                $actionImages = array_map(fn ($photo) => $photos->store($photo), array_filter($validated['action_images'] ?? []));
                $record->mt?->update([
                    'status' => $validated['inventory_status'],
                    'location' => $validated['location'],
                    'detail_location' => $validated['detail_location'] ?? null,
                    'inspection_remark' => $validated['remarks'] ?? null,
                ]);
                $findings = array_values(array_filter($validated['findings'] ?? [], fn ($finding) => filled($finding)));
                $picaNumber = $findings === [] ? null : ($record->pica_number ?: $this->nextPicaNumber($record->site));
                $record->update([
                    'condition' => $validated['condition'], 'worthiness' => $validated['worthiness'],
                    'device_status' => $validated['inventory_status'], 'remarks' => $validated['remarks'] ?? null,
                    'checklist_results_list' => json_encode($validated['checklist_results_list']),
                    'list_results_remark' => json_encode($validated['list_results_remark'] ?? []),
                    'findings' => $findings === [] ? null : json_encode($findings),
                    'findings_status' => empty($validated['findings_status']) ? null : json_encode($validated['findings_status']),
                    'findings_action' => empty($validated['findings_action']) ? null : json_encode($validated['findings_action']),
                    'due_date' => $validated['due_date'] ?? null, 'pica_number' => $picaNumber,
                    'findings_image' => $findingsImages === [] ? null : json_encode(array_values($findingsImages), JSON_UNESCAPED_SLASHES),
                    'action_image' => $actionImages === [] ? null : json_encode(array_values($actionImages), JSON_UNESCAPED_SLASHES),
                    'inspection_image' => $inspectionImage,
                    'pic' => $validated['pic'] ?? null,
                    'crew' => json_encode($validated['crew'] ?? []),
                    'list_of_needs' => json_encode($validated['list_of_needs'] ?? []),
                    'inspection_status' => 'Y', 'inspection_at' => now(), 'inspector' => $request->user()->name,
                ]);
                if (array_key_exists('devices', $validated)) {
                    app(InspectionAttachedDeviceProcessor::class)->replace($record, 'mobile_tower', $validated['devices'], $request->user(), $photos);
                }
                $pica = PicaInspeksi::where('inspeksi_id', $record->id)->first();
                if ($findings === []) {
                    $pica?->delete();

                    return;
                }
                $picaData = ['pica_number' => $picaNumber, 'temuan' => implode("\n", $findings), 'foto_temuan' => $findingsImages[0] ?? null, 'tindakan' => implode("\n", $validated['findings_action'] ?? []), 'foto_tindakan' => $actionImages[0] ?? null, 'status_pica' => implode(',', $validated['findings_status'] ?? []), 'due_date' => $validated['due_date'] ?? null, 'remark' => $validated['remarks'] ?? null, 'site' => $record->site];
                $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $record->id]);
            });
        } catch (\Throwable $exception) {
            $photos->rollback();
            throw $exception;
        }

        return response()->json(['data' => InspeksiMobileTower::with(['mt', 'devices'])->findOrFail($inspection)]);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate(['approval_type' => ['required', Rule::in(['accept', 'reject'])]]);
        $record = InspeksiMobileTower::findOrFail($inspection);
        $this->assertCanApprove($request, $record->site);
        abort_unless($record->inspection_status === 'Y', 422, 'Data ini belum diinspeksi.');
        $record->update(['approved_by' => $request->user()->name, 'status_approval' => $validated['approval_type'] === 'accept' ? 'approve' : 'reject']);

        return response()->json(['data' => InspeksiMobileTower::with(['mt', 'devices'])->findOrFail($inspection)]);
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
        return InspectionPicaNumber::next($site, 'MT');
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
