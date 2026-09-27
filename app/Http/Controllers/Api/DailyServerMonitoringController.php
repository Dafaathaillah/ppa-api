<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventorySite;
use App\Http\Controllers\Controller;
use App\Models\DailyServerMonitoring;
use App\Models\User;
use App\Support\DailyServerMonitoringPhotoStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DailyServerMonitoringController extends Controller
{
    use AuthorizesInventorySite;

    private const ROLES = ['ict_technician', 'ict_group_leader', 'ict_ho', 'ict_developer'];

    private const SITES = ['HO', 'BA', 'MIFA', 'MHU', 'ADW', 'AMI', 'PIK', 'BGE', 'BIB', 'IPT', 'MLP', 'MIP', 'VIB', 'SBS', 'SKS', 'MAC', 'DMP'];

    private const CHECKS = ['server_hypervisor_avability', 'hardware_alert_system', 'cpu_memory', 'storage_capacity', 'backup', 'critical_system_log', 'antivirus', 'time_synchronization', 'temp_humidity_room', 'server_room_condition', 'ups', 'room_condition'];

    public function options(Request $request): JsonResponse
    {
        $site = $this->site($request, $request->query('site'));

        return response()->json(['data' => [
            'check_values' => ['Y', 'N'], 'findings_statuses' => ['OPEN', 'CLOSE'],
            'inspection_statuses' => ['Normal', 'Terdapat Temuan'],
            'inspectors' => User::query()->where('site', $site)->whereIn('role', ['ict_technician', 'ict_group_leader', 'ict_developer'])->orderBy('name')->get(['name']),
            'default_site' => strtoupper(trim((string) $request->user()->site)),
            'can_access_other_sites' => in_array($request->user()->role, ['ict_ho', 'ict_developer'], true),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate($this->filterRules());
        $site = $this->site($request, $v['site'] ?? null);
        $data = DailyServerMonitoring::query()->where('site', $site)
            ->when($v['month'] ?? null, fn (Builder $q, $x) => $q->where('month', $x))
            ->when($v['year'] ?? null, fn (Builder $q, $x) => $q->where('year', $x))
            ->when($v['inspection_status'] ?? null, fn (Builder $q, $x) => $q->where('inspection_status', $x))
            ->when($v['findings_status'] ?? null, fn (Builder $q, $x) => $q->where('findings_status', $x))
            ->when($v['approval_status'] ?? null, fn (Builder $q, $x) => $x === 'approved' ? $q->where('status_approval', 'approved') : $q->whereNull('status_approval'))
            ->when($v['search'] ?? null, fn (Builder $q, $x) => $q->where(fn (Builder $q) => $q->where('inspector', 'like', "%$x%")->orWhere('findings', 'like', "%$x%")->orWhere('remarks', 'like', "%$x%")))
            ->latest()->paginate($v['per_page'] ?? 15);
        $data->getCollection()->transform(fn ($row) => $this->resource($row));

        return response()->json($data);
    }

    public function show(Request $request, string $monitoring): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->record($request, $monitoring))]);
    }

    public function store(Request $request, DailyServerMonitoringPhotoStorage $photos): JsonResponse
    {
        $v = $request->validate($this->rules());
        $site = $this->site($request, $v['site'] ?? null);
        unset($v['site']);
        $this->validateFinding($v, null, $v);
        try {
            $v = $this->storePhotos($v, $photos);
            $row = DB::transaction(fn () => DailyServerMonitoring::create([
                ...$v, 'month' => now()->month, 'year' => now()->year, 'inspection_at' => now(), 'checked_at' => now(),
                'checked_by' => $request->user()->name, 'last_edited_by' => $request->user()->nrp,
                'inspection_status' => $this->hasFinding($v) ? 'Terdapat Temuan' : 'Normal', 'site' => $site,
            ]));
        } catch (Throwable $e) {
            $photos->rollback();
            throw $e;
        }

        return response()->json(['message' => 'Daily Check Server berhasil dibuat.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, string $monitoring, DailyServerMonitoringPhotoStorage $photos): JsonResponse
    {
        $row = $this->record($request, $monitoring);
        $v = $request->validate($this->rules(true));
        $merged = [...$row->toArray(), ...$v];
        $this->validateFinding($merged, $row, $v);
        $oldPhotos = collect(['findings_image', 'action_image', 'inspection_image'])->mapWithKeys(fn ($field) => [$field => $row->{$field}]);
        try {
            $v = $this->storePhotos($v, $photos);
            $merged = [...$row->toArray(), ...$v];
            if (! $this->hasFinding($merged)) {
                foreach (['findings', 'findings_image', 'first_action', 'findings_action', 'action_image', 'findings_status'] as $field) {
                    $v[$field] = null;
                }
            }
            $v['inspection_status'] = $this->hasFinding([...$merged, ...$v]) ? 'Terdapat Temuan' : 'Normal';
            $v['inspection_at'] = now();
            $v['checked_at'] = now();
            $v['checked_by'] = $request->user()->name;
            $v['last_edited_by'] = $request->user()->nrp;
            DB::transaction(fn () => $row->update($v));
        } catch (Throwable $e) {
            $photos->rollback();
            throw $e;
        }
        foreach ($oldPhotos as $field => $oldUrl) {
            if (array_key_exists($field, $v) && $v[$field] !== $oldUrl) {
                try {
                    $photos->deleteUrl($oldUrl);
                } catch (Throwable) {
                    // The database already references the new file; cleanup is best effort.
                }
            }
        }

        return response()->json(['message' => 'Daily Check Server berhasil diperbarui.', 'data' => $this->resource($row->fresh())]);
    }

    public function approval(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ict_group_leader', 'ict_ho', 'ict_developer'], true), 403);
        $v = $request->validate(['site' => ['nullable', Rule::in(self::SITES)], 'month' => ['required', 'integer', 'between:1,12'], 'year' => ['required', 'integer', 'between:2020,2100']]);
        $site = $this->site($request, $v['site'] ?? null);
        $count = DailyServerMonitoring::query()->where('site', $site)->where('month', $v['month'])->where('year', $v['year'])->whereNotNull('checked_at')->update(['verifie_by' => $request->user()->name, 'verifie_at' => now(), 'status_approval' => 'approved']);

        return response()->json(['message' => "$count Daily Check Server berhasil disetujui.", 'data' => ['updated' => $count]]);
    }

    public function destroy(Request $request, string $monitoring): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ict_group_leader', 'ict_ho', 'ict_developer'], true), 403);
        $row = $this->record($request, $monitoring);
        $row->delete();

        return response()->json(['message' => 'Daily Check Server berhasil dihapus.']);
    }

    private function rules(bool $update = false): array
    {
        $required = $update ? ['sometimes', 'required'] : ['required'];
        $rules = ['site' => $update ? ['prohibited'] : ['nullable', Rule::in(self::SITES)], 'inspector' => [...$required, 'string', 'max:255'], 'findings' => ['nullable', 'string'], 'first_action' => ['nullable', 'string'], 'findings_action' => ['nullable', 'string'], 'findings_status' => ['nullable', Rule::in(['OPEN', 'CLOSE'])], 'remarks' => ['nullable', 'string'], 'findings_image' => ['nullable', 'image', 'max:10240'], 'action_image' => ['nullable', 'image', 'max:10240'], 'inspection_image' => ['nullable', 'image', 'max:10240']];
        foreach (self::CHECKS as $field) {
            $rules[$field] = [...$required, Rule::in(['Y', 'N'])];
        }

        return $rules;
    }

    private function filterRules(): array
    {
        return ['site' => ['nullable', Rule::in(self::SITES)], 'month' => ['nullable', 'integer', 'between:1,12'], 'year' => ['nullable', 'integer', 'between:2020,2100'], 'inspection_status' => ['nullable', Rule::in(['Normal', 'Terdapat Temuan'])], 'findings_status' => ['nullable', Rule::in(['OPEN', 'CLOSE'])], 'approval_status' => ['nullable', Rule::in(['pending', 'approved'])], 'search' => ['nullable', 'string', 'max:255'], 'per_page' => ['nullable', 'integer', 'between:1,100']];
    }

    private function hasFinding(array $data): bool
    {
        return collect(self::CHECKS)->contains(fn ($f) => ($data[$f] ?? null) === 'N') || trim((string) ($data['findings'] ?? '')) !== '';
    }

    private function validateFinding(array $data, ?DailyServerMonitoring $row, array $submitted): void
    {
        $errors = [];
        $findingImage = array_key_exists('findings_image', $submitted) ? $submitted['findings_image'] : $row?->findings_image;
        $actionImage = array_key_exists('action_image', $submitted) ? $submitted['action_image'] : $row?->action_image;
        if ($this->hasFinding($data)) {
            if (blank($data['findings'] ?? null)) {
                $errors['findings'][] = 'Deskripsi temuan wajib diisi.';
            }
            if (blank($data['findings_status'] ?? null)) {
                $errors['findings_status'][] = 'Status temuan wajib dipilih.';
            }
            if (! $findingImage) {
                $errors['findings_image'][] = 'Foto temuan wajib diunggah.';
            }
        }
        if (filled($data['findings_action'] ?? null) && ! $actionImage) {
            $errors['action_image'][] = 'Foto tindakan wajib diunggah.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function storePhotos(array $data, DailyServerMonitoringPhotoStorage $storage): array
    {
        foreach (['findings_image', 'action_image', 'inspection_image'] as $field) {
            if (($data[$field] ?? null) instanceof \Illuminate\Http\UploadedFile) {
                $data[$field] = $storage->store($data[$field]);
            }
        }

        return $data;
    }

    private function resource(DailyServerMonitoring $row): array
    {
        $data = $row->toArray();
        unset($data['user']);

        return [...$data, 'notification_count' => collect(self::CHECKS)->filter(fn ($f) => $row->{$f} !== 'Y')->count()];
    }

    private function record(Request $request, string $id): DailyServerMonitoring
    {
        $row = DailyServerMonitoring::query()->findOrFail($id);
        $this->site($request, $row->site);

        return $row;
    }

    private function site(Request $request, ?string $requested = null): string
    {
        abort_unless(in_array($request->user()->role, self::ROLES, true), 403);
        $site = $this->authorizedSite($request, $requested);
        abort_unless(in_array($site, self::SITES, true), 422);

        return $site;
    }
}
