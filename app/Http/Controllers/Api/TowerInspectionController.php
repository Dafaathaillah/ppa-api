<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ProcessesAssetInspection;
use App\Http\Controllers\Controller;
use App\Models\InspeksiTower;
use App\Models\PicaInspeksi;
use App\Support\InspectionPicaNumber;
use App\Support\TowerInspectionPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TowerInspectionController extends Controller
{
    use ProcessesAssetInspection;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->inspectionFilters($request);
        $query = InspeksiTower::query()->with(['tower.category']);
        $this->applyFilters($query, $filters, null);
        $data = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15)->through(fn (InspeksiTower $item) => $this->resource($item));

        return response()->json($data);
    }

    public function show(string $inspection): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->find($inspection))]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate([
            'kelayakan' => ['nullable', 'string', 'max:50'], 'condition' => ['required', 'string', 'max:50'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'checklist_results_list' => ['nullable', 'array'], 'list_results_remark' => ['nullable', 'array'],
            'remark' => ['nullable', 'string', 'max:2000'], 'pic' => ['nullable', 'string', 'max:255'],
            'crew' => ['nullable', 'array', 'max:5'], 'crew.*' => ['string', 'max:100'],
            'lokasi' => ['nullable', 'string', 'max:255'], 'detail_lokasi' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'], 'temuan' => ['nullable', 'array', 'max:5'],
            'temuan.*.temuan' => ['nullable', 'string', 'max:2000'], 'temuan.*.status' => ['nullable', 'string', 'max:50'],
            'temuan.*.tindak_lanjut' => ['nullable', 'string', 'max:2000'],
            'temuan.*.temuan_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'temuan.*.tindak_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'lampiran' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $record = $this->find($inspection);
        $site = strtoupper((string) $record->tower->site);
        $this->assertCanProcess($request, $site);
        $photos = new TowerInspectionPhotoStorage;

        try {
            DB::transaction(function () use ($request, $validated, $record, $site, $photos): void {
                $findings = $statuses = $actions = $findingImages = $actionImages = [];
                $existingFindingImages = $this->decodedArray($record->findings_image);
                $existingActionImages = $this->decodedArray($record->action_image);
                foreach ($validated['temuan'] ?? [] as $index => $finding) {
                    if (! filled($finding['temuan'] ?? null) && ! filled($finding['tindak_lanjut'] ?? null)) {
                        continue;
                    }
                    $findings[] = $finding['temuan'] ?? null;
                    $statuses[] = $finding['status'] ?? null;
                    $actions[] = $finding['tindak_lanjut'] ?? null;
                    $findingImages[] = $request->hasFile("temuan.$index.temuan_image")
                        ? $photos->store($request->file("temuan.$index.temuan_image"))
                        : ($existingFindingImages[$index] ?? null);
                    $actionImages[] = $request->hasFile("temuan.$index.tindak_image")
                        ? $photos->store($request->file("temuan.$index.tindak_image"))
                        : ($existingActionImages[$index] ?? null);
                }
                $hasOpenFinding = $findings !== [] && collect($statuses)->contains(fn ($status) => strtoupper((string) $status) !== 'CLOSE');
                $number = $hasOpenFinding ? ($record->pica_number ?: InspectionPicaNumber::next($site, 'TWR')) : null;
                $record->tower->update(['status' => $validated['inventory_status'], 'location' => $validated['lokasi'] ?? $record->tower->location,
                    'detail_location' => $validated['detail_lokasi'] ?? $record->tower->detail_location, 'remark_inspeksi' => $validated['remark'] ?? null]);
                $inspectionData = ['worthiness' => $validated['kelayakan'] ?? null, 'kelayakan' => $validated['kelayakan'] ?? null,
                    'condition' => $validated['condition'], 'device_status' => $validated['inventory_status'], 'inventory_status' => $validated['inventory_status'],
                    'checklist_results_list' => json_encode($validated['checklist_results_list'] ?? []), 'list_results_remark' => json_encode($validated['list_results_remark'] ?? []),
                    'remarks' => $validated['remark'] ?? null, 'pic' => $validated['pic'] ?? null, 'crew' => json_encode($validated['crew'] ?? []),
                    'findings' => $findings === [] ? null : json_encode($findings), 'findings_status' => $findings === [] ? null : json_encode($statuses),
                    'findings_action' => $findings === [] ? null : json_encode($actions), 'findings_image' => $findings === [] ? null : json_encode($findingImages, JSON_UNESCAPED_SLASHES),
                    'action_image' => $findings === [] ? null : json_encode($actionImages, JSON_UNESCAPED_SLASHES), 'due_date' => $findings === [] ? null : ($validated['due_date'] ?? null),
                    'inspection_image' => $this->storedPhoto($request, 'lampiran', $photos, $record->inspection_image), 'pica_number' => $number,
                    'inspection_status' => 'Y', 'inspection_at' => now(), 'inspector' => $request->user()->name];
                $columns = array_flip(Schema::getColumnListing('inspeksi_towers'));
                $record->forceFill(array_intersect_key($inspectionData, $columns))->save();
                $pica = $this->picaFor((string) $record->id, 'TWR');
                if (! $hasOpenFinding) {
                    $pica?->delete();

                    return;
                }
                $picaData = ['pica_number' => $number, 'temuan' => implode("\n", $findings), 'tindakan' => implode("\n", $actions),
                    'foto_temuan' => $findingImages[0] ?? null, 'foto_tindakan' => $actionImages[0] ?? null, 'status_pica' => implode(',', $statuses),
                    'due_date' => $validated['due_date'] ?? null, 'remark' => $validated['remark'] ?? null, 'site' => $site];
                $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $record->id]);
            });
        } catch (\Throwable $exception) {
            $photos->rollback();
            throw $exception;
        }

        return $this->show($inspection);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate(['approval_type' => ['required', Rule::in(['accept', 'reject'])]]);
        $record = $this->find($inspection);
        $this->assertCanApprove($request, (string) $record->tower->site);
        abort_unless(in_array($record->inspection_status, ['Y', 'sudah_inspeksi'], true), 422, 'Data ini belum diinspeksi.');
        $record->update(['approved_by' => $request->user()->name, 'status_approval' => $validated['approval_type'] === 'accept' ? 'approve' : 'reject']);

        return $this->show($inspection);
    }

    private function find(string $id): InspeksiTower
    {
        return InspeksiTower::with(['tower.category'])->findOrFail($id);
    }

    private function resource(InspeksiTower $record): array
    {
        $pica = $this->picaFor((string) $record->id, 'TWR');

        return ['id' => $record->id, 'type' => 'tower', 'site' => $record->tower?->site, 'month' => $record->month, 'year' => $record->year,
            'inspection_status' => $record->inspection_status, 'inspection_at' => $record->inspection_at, 'inspector' => $record->inspector,
            'condition' => $record->condition, 'worthiness' => $record->worthiness ?? $record->kelayakan, 'inventory_status' => $record->inventory_status ?? $record->device_status,
            'checklist_results_list' => $this->decodedArray($record->checklist_results_list), 'list_results_remark' => $this->decodedArray($record->list_results_remark),
            'findings' => $this->decodedArray($record->findings), 'findings_status' => $this->decodedArray($record->findings_status),
            'findings_action' => $this->decodedArray($record->findings_action), 'findings_image' => $this->decodedArray($record->findings_image),
            'action_image' => $this->decodedArray($record->action_image), 'inspection_image' => $record->inspection_image, 'due_date' => $record->due_date,
            'remarks' => $record->remarks, 'pic' => $record->pic, 'crew' => $this->decodedArray($record->crew), 'pica_number' => $record->pica_number,
            'status_approval' => $record->status_approval, 'approved_by' => $record->approved_by, 'inventory' => $record->tower,
            'pica' => $pica];
    }
}
