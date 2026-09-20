<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\InvComputer;
use App\Models\InvLaptop;
use App\Models\MutasiAsset;
use App\Models\User;
use App\Services\AssetMutationWorkflowService;
use App\Support\MutationAssetPdfStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssetMutationController extends Controller
{
    private const CATEGORIES = ['LAPTOP', 'COMPUTER'];

    public function __construct(
        private readonly AssetMutationWorkflowService $workflow,
        private readonly MutationAssetPdfStorage $pdfStorage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'direction' => ['nullable', Rule::in(['internal', 'outgoing', 'incoming'])],
            'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECT'])],
            'device_category' => ['nullable', Rule::in(self::CATEGORIES)],
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $direction = $validated['direction'] ?? 'outgoing';

        $data = MutasiAsset::query()
            ->when($direction === 'internal', fn ($query) => $query->where('departure_site', $site)->whereColumn('departure_site', 'arrived_site'))
            ->when($direction === 'outgoing', fn ($query) => $query->where('departure_site', $site)->whereColumn('departure_site', '!=', 'arrived_site'))
            ->when($direction === 'incoming', fn ($query) => $query->where('arrived_site', $site)->whereColumn('departure_site', '!=', 'arrived_site'))
            ->when(($validated['status'] ?? null) === 'PENDING', fn ($query) => $query->whereNull('approval_status'))
            ->when(isset($validated['status']) && $validated['status'] !== 'PENDING', fn ($query) => $query->where('approval_status', $validated['status']))
            ->when($validated['device_category'] ?? null, fn ($query, $category) => $query->where('device_category', $category))
            ->when($validated['start_date'] ?? null, fn ($query, $date) => $query->whereDate('created_date', '>=', $date))
            ->when($validated['end_date'] ?? null, fn ($query, $date) => $query->whereDate('created_date', '<=', $date))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(fn ($query) => $query->where('pic_username', 'like', "%{$search}%")
                    ->orWhere('pic_nrp', 'like', "%{$search}%")
                    ->orWhere('mutation_by', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (MutasiAsset $mutation) => $this->resource($mutation));

        return response()->json($data);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'categories' => self::CATEGORIES,
            'departments' => Department::query()->where('is_site', 'Y')->orderBy('department_name')->get(['id', 'department_name', 'code']),
            'crew' => User::query()->where('site', $site)->where(function ($query) use ($site): void {
                $site === 'HO' ? $query->where('role', 'ict_ho') : $query->whereIn('role', ['ict_technician', 'ict_group_leader']);
            })->orderBy('name')->get(['id', 'name']),
            'default_site' => strtoupper((string) $request->user()->site),
            'selected_site' => $site,
            'can_access_other_sites' => $request->user()->role === 'ict_developer',
        ]]);
    }

    public function devices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $devices = $validated['category'] === 'LAPTOP'
            ? InvLaptop::query()->where('site', $site)->orderBy('laptop_code')->get(['id', 'laptop_code as inventory_number'])
            : InvComputer::query()->where('site', $site)->orderBy('computer_code')->get(['id', 'computer_code as inventory_number']);

        return response()->json(['data' => $devices]);
    }

    public function deviceUser(Request $request, string $category, string $asset): JsonResponse
    {
        $category = strtoupper($category);
        abort_unless(in_array($category, self::CATEGORIES, true), 404);
        $device = $category === 'LAPTOP' ? InvLaptop::with('pengguna')->findOrFail($asset) : InvComputer::with('pengguna')->findOrFail($asset);
        $this->authorizedSite($request, $device->site);

        return response()->json(['data' => $device->pengguna ? [
            'name' => $device->pengguna->username,
            'nrp' => $device->pengguna->nrp,
            'position' => $device->pengguna->position,
            'department' => $device->pengguna->department,
        ] : null]);
    }

    public function nextInventoryCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'department' => ['required', 'string', 'max:255'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $department = Department::query()->where('department_name', $validated['department'])->firstOrFail();
        $prefix = ($site === 'AMI' ? 'LAC' : $site).'-'.($validated['category'] === 'LAPTOP' ? 'NB' : 'PC').'-'.strtoupper($department->code).'-';
        $lastCode = $validated['category'] === 'LAPTOP'
            ? InvLaptop::withTrashed()->where('laptop_code', 'like', $prefix.'%')->orderByDesc('laptop_code')->value('laptop_code')
            : InvComputer::withTrashed()->where('computer_code', 'like', $prefix.'%')->orderByDesc('computer_code')->value('computer_code');
        preg_match('/([0-9]+)$/', (string) $lastCode, $matches);

        return response()->json(['data' => ['code' => $prefix.str_pad((string) (((int) ($matches[1] ?? 0)) + 1), 3, '0', STR_PAD_LEFT)]]);
    }

    public function show(Request $request, string $mutation): JsonResponse
    {
        $record = MutasiAsset::query()->findOrFail($mutation);
        $this->authorizeVisibility($request, $record);

        return response()->json(['data' => $this->resource($record)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $this->findAsset($validated['device_category'], $validated['asset_id'], $site);
        $url = $this->pdfStorage->store($request->file('image'));

        try {
            unset($validated['site'], $validated['image']);
            $mutation = MutasiAsset::create([...$validated, 'departure_site' => $site, 'link_ba' => $url]);
        } catch (Throwable $exception) {
            $this->deletePdf($url);
            throw $exception;
        }

        return response()->json(['message' => 'Mutasi asset berhasil dibuat.', 'data' => $this->resource($mutation)], 201);
    }

    public function update(Request $request, string $mutation): JsonResponse
    {
        $record = MutasiAsset::query()->findOrFail($mutation);
        $this->authorizeOriginPending($request, $record);
        $validated = $request->validate($this->rules(true));
        $this->findAsset($validated['device_category'], $validated['asset_id'], $record->departure_site);
        $oldPdf = $record->link_ba;
        $newPdf = $request->hasFile('image') ? $this->pdfStorage->store($request->file('image')) : null;

        try {
            unset($validated['site'], $validated['image']);
            if ($newPdf) {
                $validated['link_ba'] = $newPdf;
            }
            $record->update($validated);
        } catch (Throwable $exception) {
            if ($newPdf) {
                $this->deletePdf($newPdf);
            }
            throw $exception;
        }
        if ($newPdf && $oldPdf) {
            $this->deletePdf($oldPdf);
        }

        return response()->json(['message' => 'Mutasi asset berhasil diperbarui.', 'data' => $this->resource($record->fresh())]);
    }

    public function destroy(Request $request, string $mutation): JsonResponse
    {
        $record = MutasiAsset::query()->findOrFail($mutation);
        $this->authorizeOriginPending($request, $record);
        $pdf = $record->link_ba;
        $record->delete();
        if ($pdf) {
            $this->deletePdf($pdf);
        }

        return response()->json(['message' => 'Mutasi asset berhasil dihapus.']);
    }

    public function accept(Request $request, string $mutation): JsonResponse
    {
        $validated = $request->validate([
            'category_asset' => ['required', Rule::in(self::CATEGORIES)],
            'department' => ['required', 'string', 'max:255'],
            'generated_code' => ['required', 'string', 'max:255'],
        ]);
        $record = MutasiAsset::query()->findOrFail($mutation);
        $this->authorizeDestinationApprover($request, $record);
        $department = Department::query()->where('department_name', $validated['department'])->first();
        if (! $department) {
            throw ValidationException::withMessages(['department' => ['Department tidak ditemukan.']]);
        }
        $record = $this->workflow->accept($record, $validated['category_asset'], $department, strtoupper($validated['generated_code']), $request->user());

        return response()->json(['message' => 'Asset berhasil diterima.', 'data' => $this->resource($record)]);
    }

    public function reject(Request $request, string $mutation): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = MutasiAsset::query()->findOrFail($mutation);
        $this->authorizeDestinationApprover($request, $record);
        $record = $this->workflow->reject($record, $validated['reason'], $request->user());

        return response()->json(['message' => 'Mutasi asset ditolak.', 'data' => $this->resource($record)]);
    }

    private function rules(bool $updating = false): array
    {
        return [
            'device_category' => ['required', Rule::in(self::CATEGORIES)],
            'asset_id' => ['required', 'uuid'],
            'mutation_by' => ['required', 'string', 'max:255'],
            'pic_username' => ['required', 'string', 'max:255'],
            'pic_nrp' => ['required', 'string', 'max:255'],
            'pic_position' => ['nullable', 'string', 'max:255'],
            'pic_department' => ['nullable', 'string', 'max:255'],
            'site' => [$updating ? 'prohibited' : 'nullable', 'string', 'max:25'],
            'arrived_site' => ['required', 'string', 'max:25'],
            'created_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'image' => [$updating ? 'nullable' : 'required', 'file', 'mimes:pdf', 'mimetypes:application/pdf,application/x-pdf', 'max:20480'],
        ];
    }

    private function authorizedSite(Request $request, ?string $requestedSite): string
    {
        $userSite = strtoupper(trim((string) $request->user()->site));
        $site = strtoupper(trim((string) ($requestedSite ?: $userSite)));
        abort_if($site === '' || $userSite === '', 422, 'Site user tidak valid.');
        abort_if($site !== $userSite && $request->user()->role !== 'ict_developer', 403, 'Anda tidak memiliki akses ke site tersebut.');

        return $site;
    }

    private function authorizeVisibility(Request $request, MutasiAsset $mutation): void
    {
        abort_if($request->user()->role !== 'ict_developer' && ! in_array(strtoupper((string) $request->user()->site), [$mutation->departure_site, $mutation->arrived_site], true), 403);
    }

    private function authorizeOriginPending(Request $request, MutasiAsset $mutation): void
    {
        abort_if($request->user()->role !== 'ict_developer' && strtoupper((string) $request->user()->site) !== $mutation->departure_site, 403);
        if (! $mutation->canBeProcessed()) {
            throw ValidationException::withMessages(['mutation' => ['Mutasi asset ini sudah selesai diproses.']]);
        }
    }

    private function authorizeDestinationApprover(Request $request, MutasiAsset $mutation): void
    {
        $user = $request->user();
        $allowed = $user->role === 'ict_developer'
            || ($user->role === 'ict_group_leader' && strtoupper((string) $user->site) === $mutation->arrived_site)
            || ($user->role === 'ict_ho' && strtoupper((string) $user->site) === 'HO' && $mutation->arrived_site === 'HO');
        abort_unless($allowed, 403, 'Unauthorized');
    }

    private function findAsset(string $category, string $assetId, string $site): InvLaptop|InvComputer
    {
        $asset = $category === 'LAPTOP' ? InvLaptop::query()->findOrFail($assetId) : InvComputer::query()->findOrFail($assetId);
        if (strtoupper($asset->site) !== strtoupper($site)) {
            throw ValidationException::withMessages(['asset_id' => ['Asset tidak berada pada site keberangkatan.']]);
        }

        return $asset;
    }

    private function resource(MutasiAsset $mutation): array
    {
        $device = $mutation->device_category === 'LAPTOP' ? $mutation->laptop : $mutation->computer;

        return [...$mutation->toArray(), 'device' => $device];
    }

    private function deletePdf(string $url): void
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = str_starts_with($path, 'storage/') ? substr($path, 8) : $path;
        Storage::disk('public')->delete($path);
    }
}
