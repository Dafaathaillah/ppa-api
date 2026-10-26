<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\PicaInspeksi;
use App\Support\Api\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Intervention\Image\Laravel\Facades\Image;

abstract class ProcessesNetworkInspection
{
    use PresentsInspectionList;

    abstract protected function inspectionModel(): string;

    abstract protected function picaDeviceCode(): string;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'inspection_status' => ['nullable', 'string', 'max:255'],
            'status_approval' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $model = $this->inspectionModel();
        $query = $model::query()
            ->with('inventory');
        $canAccessAnySite = SiteContext::canAccessAnySite($request);
        $site = $canAccessAnySite ? ($filters['site'] ?? null) : SiteContext::resolve($request);
        if (! $canAccessAnySite && $site === null) {
            $query->whereRaw('1 = 0');
        } elseif ($site !== null) {
            $query->where('site', strtoupper($site));
        }
        $query
            ->when($filters['month'] ?? null, fn ($query, $month) => $query->where('month', $month))
            ->when($filters['year'] ?? null, fn ($query, $year) => $query->where('year', $year))
            ->when($filters['inspection_status'] ?? null, fn ($query, $status) => $query->where('inspection_status', $status))
            ->when($filters['status_approval'] ?? null, fn ($query, $status) => $query->where('status_approval', $status));
        $this->applyInspectionSearch(
            $query,
            $filters['search'] ?? null,
            ['pica_number', 'ip_address', 'condition', 'device_status', 'remarks', 'findings', 'corrective_action', 'finding_status'],
            'inventory',
            ['inventory_number', 'device_name', 'ip_address', 'location']
        );
        $data = $query->latest('inspection_at')
            ->paginate($filters['per_page'] ?? 15)
            ->through(fn (Model $inspection) => $this->resource($inspection));

        return response()->json($data);
    }

    public function show(Request $request, string $inspection): JsonResponse
    {
        return response()->json([
            'data' => $this->resource($this->findInspection($inspection, $request)),
        ]);
    }

    public function process(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate([
            'device_status' => ['required', 'string', 'max:255'],
            'condition' => ['required', 'string', 'max:255'],
            'inventory_status' => ['required', Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'location' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'has_findings' => ['required', 'boolean'],
            'findings' => ['nullable', 'string', 'required_if:has_findings,true'],
            'corrective_action' => ['nullable', 'string'],
            'finding_status' => ['nullable', 'string', 'max:255'],
            'target_completion_date' => ['nullable', 'date'],
            'findings_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'corrective_action_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ]);
        $record = $this->findInspection($inspection, $request);
        abort_unless(strtoupper((string) $record->site) === strtoupper((string) $request->user()->site), 403, 'Anda hanya dapat memproses inspeksi site sendiri.');
        $findingsImage = $request->hasFile('findings_image') ? $this->storeImage($request, 'findings_image') : $record->findings_image;
        $correctiveActionImage = $request->hasFile('corrective_action_image') ? $this->storeImage($request, 'corrective_action_image') : $record->corrective_action_image;

        DB::transaction(function () use ($request, $record, $validated, $findingsImage, $correctiveActionImage): void {
            $record->inventory?->update(['status' => $validated['inventory_status'], 'location' => $validated['location']]);
            $base = [
                'inspection_status' => 'Y', 'inspection_at' => now(), 'inspection_by' => $request->user()->name,
                'device_status' => $validated['device_status'], 'condition' => $validated['condition'],
                'inventory_status' => $validated['inventory_status'], 'remarks' => $validated['remarks'] ?? null,
                'has_findings' => $validated['has_findings'],
                'findings_image' => $findingsImage,
                'corrective_action_image' => $correctiveActionImage,
            ];

            if (! $validated['has_findings']) {
                PicaInspeksi::where('inspeksi_id', $record->getKey())->delete();
                $record->update([...$base, 'findings' => null, 'corrective_action' => null, 'finding_status' => null, 'target_completion_date' => null, 'pica_number' => null]);
                return;
            }

            $pica = PicaInspeksi::where('inspeksi_id', $record->getKey())->first();
            $number = $record->pica_number ?: $this->nextPicaNumber($record->site);
            $finding = [
                'findings' => $validated['findings'], 'corrective_action' => $validated['corrective_action'] ?? null,
                'finding_status' => $validated['finding_status'] ?? null, 'target_completion_date' => $validated['target_completion_date'] ?? null,
                'pica_number' => $number,
            ];
            $record->update([...$base, ...$finding]);
            $picaData = ['pica_number' => $number, 'temuan' => $finding['findings'], 'tindakan' => $finding['corrective_action'], 'status_pica' => $finding['finding_status'], 'due_date' => $finding['target_completion_date'], 'site' => $record->site];
            $pica ? $pica->update($picaData) : PicaInspeksi::create([...$picaData, 'inspeksi_id' => $record->getKey()]);
        });

        return response()->json(['data' => $this->resource($this->findInspection($inspection, $request))]);
    }

    public function approval(Request $request, string $inspection): JsonResponse
    {
        $validated = $request->validate([
            'approval_type' => ['required', Rule::in(['accept', 'reject'])],
        ]);
        $record = $this->findInspection($inspection, $request);
        abort_unless($request->user()->role === 'ict_group_leader', 403, 'Hanya Group Leader yang dapat melakukan approval inspeksi.');
        abort_unless(strtoupper((string) $record->site) === strtoupper((string) $request->user()->site), 403, 'Group Leader hanya dapat melakukan approval untuk site sendiri.');
        abort_unless(in_array($record->inspection_status, ['Y', 'sudah_inspeksi'], true), 422, 'Data ini belum diinspeksi.');

        $record->update([
            'approved_by' => $request->user()->name,
            'status_approval' => $validated['approval_type'] === 'accept' ? 'approved' : 'reject',
        ]);

        return response()->json(['data' => $this->resource($this->findInspection($inspection, $request))]);
    }

    private function nextPicaNumber(string $site): string
    {
        $count = PicaInspeksi::where('site', $site)->count() + 1;
        return sprintf('%s/PICA/%s/%03d', strtoupper($site), $this->picaDeviceCode(), $count);
    }

    private function storeImage(Request $request, string $field): string
    {
        $file = $request->file($field);
        $image = Image::read($file);
        if ($image->width() > 1600) $image->scale(width: 1600);
        $directory = public_path('images');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $filename = uniqid('', true).'.'.$file->getClientOriginalExtension();
        $image->save($directory.DIRECTORY_SEPARATOR.$filename, quality: 80);
        return asset('images/'.$filename);
    }

    protected function findInspection(string $id, ?Request $request = null): Model
    {
        $model = $this->inspectionModel();

        $query = $model::query()->with('inventory');
        if ($request && ! SiteContext::canAccessAnySite($request)) {
            $site = SiteContext::resolve($request);
            $site === null ? $query->whereRaw('1 = 0') : $query->where('site', $site);
        }

        return $query->findOrFail($id);
    }

    protected function resource(Model $inspection): array
    {
        return [
            'id' => $inspection->getKey(),
            'site' => $inspection->site,
            'month' => $inspection->month,
            'year' => $inspection->year,
            'inspection_status' => $inspection->inspection_status,
            'inspection_at' => $inspection->inspection_at,
            'inspection_by' => $inspection->inspection_by,
            'condition' => $inspection->condition,
            'device_status' => $inspection->device_status,
            'inventory_status' => $inspection->inventory_status,
            'has_findings' => $inspection->has_findings,
            'findings' => $inspection->findings,
            'findings_image' => $inspection->findings_image,
            'corrective_action' => $inspection->corrective_action,
            'corrective_action_image' => $inspection->corrective_action_image,
            'finding_status' => $inspection->finding_status,
            'target_completion_date' => $inspection->target_completion_date,
            'pica_number' => $inspection->pica_number,
            'remarks' => $inspection->remarks,
            ...$this->approvalResource($inspection),
            'inventory' => $inspection->inventory ? [
                'id' => $inspection->inventory->getKey(),
                'inventory_number' => $inspection->inventory->inventory_number,
                'device_name' => $inspection->inventory->device_name,
                'location' => $inspection->inventory->location,
                'status' => $inspection->inventory->status,
                'site' => $inspection->inventory->site,
            ] : null,
        ];
    }
}
