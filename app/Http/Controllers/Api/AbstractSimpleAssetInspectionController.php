<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ProcessesAssetInspection;
use App\Http\Controllers\Controller;
use App\Models\PicaInspeksi;
use App\Support\InspectionPicaNumber;
use App\Support\Api\SiteContext;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

abstract class AbstractSimpleAssetInspectionController extends Controller
{
    use ProcessesAssetInspection;

    abstract protected function modelClass(): string;

    abstract protected function relation(): string;

    abstract protected function deviceType(): string;

    abstract protected function picaCode(): string;

    abstract protected function checklistFields(): array;

    abstract protected function inventoryResource(Model $inventory): array;

    protected function remarkField(): string
    {
        return 'remarks';
    }

    protected function eagerLoads(): array
    {
        return [$this->relation()];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->inspectionFilters($request);
        if (! SiteContext::canAccessAnySite($request)) {
            $filters['site'] = SiteContext::resolve($request);
        }
        $model = $this->modelClass();
        $query = $model::query()->with($this->eagerLoads());
        $this->scopeInspectionAccess($query, $request);
        $this->applyFilters($query, $filters);
        $data = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15)->through(fn (Model $item) => $this->resource($item));

        return response()->json($data);
    }

    public function show(Request $request, string $inspection): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->find($inspection, $request))]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $rules = ['condition' => ['required', 'string', 'max:255'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'temuan' => ['nullable', 'string', 'max:5000'], 'tindakan' => ['nullable', 'string', 'max:5000'],
            'findings_status' => ['nullable', 'string', 'max:255'], 'due_date' => ['nullable', 'date'],
            $this->remarkField() => ['nullable', 'string', 'max:5000'],
            'image_inspeksi' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'image_temuan' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'image_tindakan' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']];
        foreach ($this->checklistFields() as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);
        $record = $this->find($inspection, $request);
        $site = $this->site($record);
        $this->assertCanProcess($request, $site);
        $photos = new TowerInspectionPhotoStorage;

        try {
            DB::transaction(function () use ($request, $validated, $record, $site, $photos): void {
                $remark = $validated[$this->remarkField()] ?? null;
                $hasFinding = filled($validated['temuan'] ?? null);
                $findingPhoto = $this->storedPhoto($request, 'image_temuan', $photos, $record->findings_image);
                $actionPhoto = $this->storedPhoto($request, 'image_tindakan', $photos, $record->action_image);
                $inspectionPhoto = $this->storedPhoto($request, 'image_inspeksi', $photos, $record->inspection_image);
                $number = $hasFinding ? ($record->pica_number ?: InspectionPicaNumber::next($site, $this->picaCode())) : null;
                $inventory = $record->getRelation($this->relation());
                $inventoryData = ['status' => $validated['inventory_status']];
                if ($this->deviceType() === 'server') {
                    $inventoryData['inspection_remark'] = $remark;
                } elseif (array_key_exists('inspection_remark', $inventory->getAttributes())) {
                    $inventoryData['inspection_remark'] = $remark;
                }
                $inventory->update($inventoryData);
                $data = ['condition' => $validated['condition'], 'inventory_status' => $validated['inventory_status'],
                    'findings' => $hasFinding ? $validated['temuan'] : null, 'findings_action' => $hasFinding ? ($validated['tindakan'] ?? null) : null,
                    'findings_status' => $hasFinding ? ($validated['findings_status'] ?? null) : null, 'due_date' => $hasFinding ? ($validated['due_date'] ?? null) : null,
                    $this->remarkField() => $remark, 'findings_image' => $hasFinding ? $findingPhoto : null, 'action_image' => $hasFinding ? $actionPhoto : null,
                    'inspection_image' => $inspectionPhoto, 'pica_number' => $number, 'inspection_status' => 'Y', 'inspection_at' => now(),
                    'inspector' => $request->user()->name];
                foreach ($this->checklistFields() as $field) {
                    $data[$field] = $validated[$field];
                }
                $record->update($data);
                $pica = $this->picaFor((string) $record->id, $this->picaCode());
                if (! $hasFinding) {
                    $pica?->delete();

                    return;
                }
                $picaData = ['pica_number' => $number, 'temuan' => $validated['temuan'], 'tindakan' => $validated['tindakan'] ?? null,
                    'foto_temuan' => $findingPhoto, 'foto_tindakan' => $actionPhoto, 'status_pica' => $validated['findings_status'] ?? null,
                    'due_date' => $validated['due_date'] ?? null, 'remark' => $remark, 'site' => $site];
                $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $record->id]);
            });
        } catch (\Throwable $exception) {
            $photos->rollback();
            throw $exception;
        }

        return $this->show($request, $inspection);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate(['approval_type' => ['required', Rule::in(['accept', 'reject'])]]);
        $record = $this->find($inspection, $request);
        $this->assertCanApprove($request, $this->site($record));
        abort_unless(in_array($record->inspection_status, ['Y', 'sudah_inspeksi'], true), 422, 'Data ini belum diinspeksi.');
        $record->update(['approved_by' => $request->user()->name, 'status_approval' => $validated['approval_type'] === 'accept' ? 'approved' : 'reject']);

        return $this->show($request, $inspection);
    }

    protected function find(string $id, ?Request $request = null): Model
    {
        $model = $this->modelClass();
        $query = $model::with($this->eagerLoads());
        if ($request) {
            $this->scopeInspectionAccess($query, $request);
        }

        return $query->findOrFail($id);
    }

    protected function site(Model $record): string
    {
        return strtoupper((string) ($record->site ?: $record->getRelation($this->relation())->site));
    }

    protected function resource(Model $record): array
    {
        $inventory = $record->getRelation($this->relation());
        $data = ['id' => $record->getKey(), 'type' => $this->deviceType(), 'site' => $this->site($record), 'month' => $record->month,
            'year' => $record->year, 'inspection_status' => $record->inspection_status, 'inspection_at' => $record->inspection_at,
            'inspector' => $record->inspector, 'condition' => $record->condition, 'inventory_status' => $record->inventory_status,
            'findings' => $record->findings, 'findings_action' => $record->findings_action, 'findings_status' => $record->findings_status,
            'findings_image' => $record->findings_image, 'action_image' => $record->action_image, 'inspection_image' => $record->inspection_image,
            'due_date' => $record->due_date, 'remarks' => $record->{$this->remarkField()}, 'pica_number' => $record->pica_number,
            'status_approval' => $record->status_approval, 'approved_by' => $record->approved_by,
            'inventory' => $this->inventoryResource($inventory), 'pica' => $this->picaFor((string) $record->getKey(), $this->picaCode())];
        foreach ($this->checklistFields() as $field) {
            $data[$field] = $record->{$field};
        }

        return $data;
    }
}
