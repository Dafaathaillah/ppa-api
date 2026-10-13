<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PicaInspeksi;
use App\Support\Api\SiteContext;
use App\Support\InspectionPicaNumber;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

abstract class AbstractVideoInspectionController extends Controller
{
    abstract protected function inspectionModel(): string;

    abstract protected function inventoryRelation(): string;

    abstract protected function findingsRelation(): string;

    abstract protected function findingForeignKey(): string;

    abstract protected function picaCode(): string;

    /** @return list<string> */
    abstract protected function checklistFields(): array;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2500'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'status_approval' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $model = $this->inspectionModel();
        $site = SiteContext::resolve($request);
        $relation = $this->inventoryRelation();
        $query = $model::query()->with([$relation, $this->findingsRelation()]);

        if ($site !== null) {
            $query->where('site', $site);
        }

        $query->when($filters['month'] ?? null, fn ($q, $value) => $q->where('inspection_month', $value))
            ->when($filters['year'] ?? null, fn ($q, $value) => $q->where('inspection_year', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['status_approval'] ?? null, fn ($q, $value) => $q->where('status_approval', $value))
            ->when($filters['location'] ?? null, fn ($q, $value) => $q->whereHas($relation, fn ($inventory) => $inventory->where('location', $value)));

        return response()->json($query->orderByDesc('inspection_year')->orderByDesc('inspection_month')
            ->paginate($filters['per_page'] ?? 15));
    }

    public function show(Request $request, string $inspection): JsonResponse
    {
        return response()->json(['data' => $this->findAuthorized($request, $inspection)]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $record = $this->findAuthorized($request, $inspection);
        $site = strtoupper((string) $record->site);
        $this->assertCanProcess($request, $site);

        $rules = [
            'condition' => ['required', 'string', 'max:255'],
            'worthiness' => ['required', 'string', 'max:255'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP', 'VIEW_ONLY'])],
            'location' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'findings' => ['nullable', 'array'],
            'findings.*.finding' => ['required_with:findings', 'string', 'max:5000'],
            'findings.*.action_taken' => ['nullable', 'string', 'max:5000'],
            'findings.*.due_date' => ['nullable', 'date'],
            'findings.*.pic_name' => ['nullable', 'string', 'max:255'],
            'findings.*.remark' => ['nullable', 'string', 'max:5000'],
            'findings.*.status' => ['required_with:findings', 'string', 'max:255'],
            'findings.*.action_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
        foreach ($this->checklistFields() as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);
        $photos = new TowerInspectionPhotoStorage;

        try {
            DB::transaction(function () use ($request, $record, $validated, $site, $photos): void {
                $inventory = $record->getRelation($this->inventoryRelation());
                $inventoryData = ['status' => $validated['inventory_status']];
                if (array_key_exists('location', $validated)) {
                    $inventoryData['location'] = $validated['location'];
                }
                $inventory->update($inventoryData);

                $inspectionData = [
                    'remark' => $validated['remark'] ?? null,
                    'kondisi' => $validated['condition'],
                    'kelayakan' => $validated['worthiness'],
                    'inv_status' => $validated['inventory_status'],
                    'status' => 'Y',
                    'inspection_date' => now(),
                    'inspector_name' => $request->user()->name,
                ];
                foreach ($this->checklistFields() as $field) {
                    $inspectionData[$field] = $validated[$field];
                }
                $record->update($inspectionData);

                $record->{$this->findingsRelation()}()->delete();
                PicaInspeksi::where('inspeksi_id', $record->id)
                    ->where('pica_number', 'like', '%/PICA/'.$this->picaCode().'/%')->delete();
                $findingPhoto = isset($validated['evidence']) ? $photos->store($validated['evidence']) : null;

                foreach ($validated['findings'] ?? [] as $finding) {
                    $actionPhoto = isset($finding['action_photo']) ? $photos->store($finding['action_photo']) : null;
                    $isClosed = in_array(strtoupper((string) $finding['status']), ['CLOSE', 'CLOSED'], true);
                    $picaNumber = $isClosed ? null : InspectionPicaNumber::next($site, $this->picaCode());
                    $findingData = [
                        $this->findingForeignKey() => $record->id,
                        'pica_number' => $picaNumber,
                        'finding' => $finding['finding'],
                        'finding_photo' => $findingPhoto,
                        'action_taken' => $finding['action_taken'] ?? null,
                        'action_photo' => $actionPhoto,
                        'due_date' => $finding['due_date'] ?? null,
                        'pic_name' => $finding['pic_name'] ?? null,
                        'remark' => $finding['remark'] ?? null,
                        'status' => $finding['status'],
                    ];
                    if ($this->picaCode() === 'CCTV') {
                        $findingData['cctv_id'] = $record->cctv_id;
                    }
                    $record->{$this->findingsRelation()}()->create($findingData);

                    if (! $isClosed) {
                        PicaInspeksi::create([
                            'pica_number' => $picaNumber, 'inspeksi_id' => $record->id,
                            'temuan' => $finding['finding'], 'tindakan' => $finding['action_taken'] ?? null,
                            'foto_temuan' => $findingPhoto, 'foto_tindakan' => $actionPhoto,
                            'status_pica' => $finding['status'], 'due_date' => $finding['due_date'] ?? null,
                            'remark' => $finding['remark'] ?? null, 'site' => $site,
                            'close_by' => $request->user()->name,
                        ]);
                    }
                }
            });
        } catch (\Throwable $exception) {
            $photos->rollback();
            throw $exception;
        }

        return $this->show($request, $inspection);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $record = $this->findAuthorized($request, $inspection);
        $this->assertCanApprove($request, strtoupper((string) $record->site));
        $validated = $request->validate(['approval_type' => ['required', Rule::in(['accept', 'reject'])]]);
        abort_unless($record->status === 'Y', 422, 'Data ini belum diinspeksi.');
        $record->update([
            'approved_by' => $request->user()->name,
            'status_approval' => $validated['approval_type'] === 'accept' ? 'approved' : 'reject',
        ]);

        return $this->show($request, $inspection);
    }

    protected function findAuthorized(Request $request, string $id): Model
    {
        $model = $this->inspectionModel();
        $query = $model::with([$this->inventoryRelation(), $this->findingsRelation()]);
        if (! SiteContext::canAccessAnySite($request)) {
            $query->where('site', strtoupper((string) $request->user()->site));
        }

        return $query->findOrFail($id);
    }

    private function assertCanProcess(Request $request, string $site): void
    {
        abort_unless(in_array($request->user()->role, ['ict_technician', 'ict_group_leader'], true)
            && strtoupper((string) $request->user()->site) === $site, 403,
            'Anda hanya dapat memproses inspeksi site sendiri.');
    }

    private function assertCanApprove(Request $request, string $site): void
    {
        abort_unless($request->user()->role === 'ict_group_leader'
            && strtoupper((string) $request->user()->site) === $site, 403,
            'Group Leader hanya dapat melakukan approval untuk site sendiri.');
    }
}
