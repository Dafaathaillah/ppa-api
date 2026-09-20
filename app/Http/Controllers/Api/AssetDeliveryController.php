<?php

namespace App\Http\Controllers\Api;

use App\Exports\DeliverAssetTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\DeliverAssetImport;
use App\Imports\DeliverAssetPreviewImport;
use App\Models\DeliverAsset;
use App\Models\Department;
use App\Models\InvLaptop;
use App\Models\UserAll;
use App\Services\AssetDeliveryWorkflowService;
use App\Support\MutationAssetPdfStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AssetDeliveryController extends Controller
{
    public function __construct(
        private readonly AssetDeliveryWorkflowService $workflow,
        private readonly MutationAssetPdfStorage $pdfStorage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site' => ['nullable', 'string', 'max:25'],
            'direction' => ['nullable', Rule::in(['outgoing', 'incoming'])],
            'status' => ['nullable', Rule::in(['WAITING_RECEIVE', 'RECEIVED', 'REJECTED'])],
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $direction = $validated['direction'] ?? ($site === 'HO' ? 'outgoing' : 'incoming');
        $data = DeliverAsset::query()
            ->with('laptop.pengguna')
            ->where($direction === 'outgoing' ? 'departure_site' : 'arrived_site', $site)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['start_date'] ?? null, fn ($query, $date) => $query->whereDate('delivery_date', '>=', $date))
            ->when($validated['end_date'] ?? null, fn ($query, $date) => $query->whereDate('delivery_date', '<=', $date))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('delivery_by', 'like', "%{$search}%")
                        ->orWhere('received_by', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('laptop', fn ($laptop) => $laptop->where('laptop_code', 'like', "%{$search}%")->orWhere('laptop_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($data);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);

        return response()->json(['data' => [
            'departments' => Department::query()->where('is_site', 'Y')->orderBy('department_name')->get(['id', 'department_name', 'code']),
            'default_site' => strtoupper((string) $request->user()->site),
            'selected_site' => $site,
            'can_access_other_sites' => $request->user()->role === 'ict_developer',
        ]]);
    }

    public function laptops(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $waiting = DeliverAsset::query()->where('status', 'WAITING_RECEIVE')->pluck('asset_id');
        $data = InvLaptop::query()->where('site', $site)->where('dept', 'STK')
            ->whereIn('status', ['READY_STANDBY', 'READY_USED', 'READY_STANBY'])
            ->whereNotIn('id', $waiting)->orderBy('laptop_code')->get()->map(fn (InvLaptop $item) => [
                'id' => $item->id, 'inventory_number' => $item->laptop_code, 'serial_number' => $item->serial_number,
                'laptop_name' => $item->laptop_name, 'spesifikasi' => $item->spesifikasi, 'location' => $item->location,
                'date_of_inventory' => $item->date_of_inventory, 'asset_ho_number' => $item->number_asset_ho,
                'site' => $item->site, 'dept' => $item->dept,
            ]);

        return response()->json(['data' => $data]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $data = UserAll::query()->where('site', $site)->orderBy('username')->get()->map(fn (UserAll $item) => [
            'id' => $item->id, 'name' => $item->username, 'label' => $item->nrp.' - '.$item->username,
            'nrp' => $item->nrp, 'department' => $item->department, 'position' => $item->position, 'site' => $item->site,
        ]);

        return response()->json(['data' => $data]);
    }

    public function nextInventoryCode(Request $request): JsonResponse
    {
        $validated = $request->validate(['site' => ['nullable', 'string', 'max:25'], 'department' => ['required', 'string', 'max:255']]);
        $site = $this->authorizedSite($request, $validated['site'] ?? null);
        $department = Department::query()->where('department_name', $validated['department'])->first();
        if (! $department) {
            return response()->json(['data' => ['code' => '-']]);
        }
        $prefix = ($site === 'AMI' ? 'LAC' : $site).'-NB-'.strtoupper($department->code).'-';
        $last = InvLaptop::withTrashed()->where('laptop_code', 'like', $prefix.'%')->orderByDesc('laptop_code')->value('laptop_code');
        $number = $last ? ((int) substr($last, -3)) + 1 : 1;

        return response()->json(['data' => ['code' => $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT)]]);
    }

    public function show(Request $request, string $delivery): JsonResponse
    {
        $record = DeliverAsset::with('laptop.pengguna')->findOrFail($delivery);
        $this->authorizeVisible($request, $record);

        return response()->json(['data' => $record]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'uuid', 'exists:inv_laptops,id'],
            'arrived_site' => ['required', 'string', 'max:25'],
            'note' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf,application/x-pdf', 'max:20480'],
        ]);
        $laptop = InvLaptop::query()->findOrFail($validated['asset_id']);
        $this->authorizedSite($request, $laptop->site);
        $pdf = $request->hasFile('image') ? $this->pdfStorage->store($request->file('image')) : null;
        try {
            $delivery = $this->workflow->create($laptop->id, $validated['arrived_site'], $validated['note'] ?? null, $pdf, $request->user());
        } catch (Throwable $exception) {
            if ($pdf) {
                $this->deletePdf($pdf);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Laptop berhasil dikirim.', 'data' => $delivery->load('laptop')], 201);
    }

    public function receive(Request $request, string $delivery): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'uuid'], 'inventory_number' => ['required', 'string', 'max:255'],
            'asset_ho_number' => ['nullable', 'string', 'max:255'], 'department' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'integer', 'exists:user_alls,id'], 'username' => ['required', 'string', 'max:255'],
            'nrp' => ['nullable', 'string', 'max:255'], 'position' => ['nullable', 'string', 'max:255'],
            'license' => ['nullable', 'string'], 'deploy_date' => ['nullable', 'date'], 'note' => ['nullable', 'string'],
        ]);
        $record = DeliverAsset::query()->findOrFail($delivery);
        $this->authorizeDestination($request, $record);
        $record = $this->workflow->receive($record, $validated, $request->user());

        return response()->json(['message' => 'Laptop berhasil diterima.', 'data' => $record->load('laptop.pengguna')]);
    }

    public function reject(Request $request, string $delivery): JsonResponse
    {
        $validated = $request->validate(['reject_reason' => ['nullable', 'string', 'max:1000']]);
        $record = DeliverAsset::query()->findOrFail($delivery);
        $this->authorizeDestination($request, $record);
        $record = $this->workflow->reject($record, $validated['reject_reason'] ?? null, $request->user());

        return response()->json(['message' => 'Delivery berhasil direject.', 'data' => $record]);
    }

    public function destroy(Request $request, string $delivery): JsonResponse
    {
        $record = DeliverAsset::query()->findOrFail($delivery);
        abort_if($request->user()->role !== 'ict_developer' && strtoupper((string) $request->user()->site) !== $record->departure_site, 403);
        if ($record->status === 'RECEIVED') {
            throw ValidationException::withMessages(['delivery' => ['Laptop yang sudah diterima tidak dapat dihapus.']]);
        }
        $record->delete();

        return response()->json(['message' => 'Delivery berhasil dihapus.']);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls']]);
        $import = new DeliverAssetPreviewImport;
        Excel::import($import, $request->file('file'));

        return response()->json(['data' => $import->rows]);
    }

    public function importStore(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls']]);
        $import = new DeliverAssetImport;
        Excel::import($import, $request->file('file'));
        $success = 0;
        $failed = 0;
        $errors = [];
        DB::transaction(function () use ($import, $request, &$success, &$failed, &$errors): void {
            foreach ($import->rows as $index => $row) {
                $inventory = trim($row['inventory_number'] ?? '');
                $destination = strtoupper(trim($row['destination_site'] ?? ''));
                $laptop = $inventory === '' ? null : InvLaptop::query()->where('laptop_code', $inventory)->first();
                $message = $inventory === '' ? 'Inventory Number kosong.' : (! $laptop ? 'Inventory tidak ditemukan.' : null);
                if ($laptop && DeliverAsset::query()->where('asset_id', $laptop->id)->where('status', 'WAITING_RECEIVE')->exists()) {
                    $message = 'Laptop masih dalam proses delivery.';
                }
                if ($laptop && $request->user()->role !== 'ict_developer' && strtoupper((string) $request->user()->site) !== strtoupper($laptop->site)) {
                    $message = 'Anda tidak memiliki akses ke site laptop.';
                }
                if ($message) {
                    $failed++;
                    $errors[] = ['row' => $index + 2, 'inventory' => $inventory, 'message' => $message];

                    continue;
                }
                DeliverAsset::create([
                    'asset_id' => $laptop->id, 'device_category' => 'LAPTOP', 'departure_site' => $laptop->site,
                    'arrived_site' => $destination, 'delivery_by' => $request->user()->name, 'status' => 'WAITING_RECEIVE',
                    'note' => trim($row['note'] ?? ''), 'delivery_date' => now(),
                ]);
                $success++;
            }
        });

        return response()->json(['success' => $success, 'failed' => $failed, 'errors' => $errors, 'message' => 'Import selesai.']);
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new DeliverAssetTemplateExport, 'Template_Deliver_Asset.xlsx');
    }

    private function authorizedSite(Request $request, ?string $requested): string
    {
        $userSite = strtoupper(trim((string) $request->user()->site));
        $site = strtoupper(trim((string) ($requested ?: $userSite)));
        abort_if($site !== $userSite && $request->user()->role !== 'ict_developer', 403);

        return $site;
    }

    private function authorizeVisible(Request $request, DeliverAsset $delivery): void
    {
        abort_if($request->user()->role !== 'ict_developer' && ! in_array(strtoupper((string) $request->user()->site), [$delivery->departure_site, $delivery->arrived_site], true), 403);
    }

    private function authorizeDestination(Request $request, DeliverAsset $delivery): void
    {
        abort_if($request->user()->role !== 'ict_developer' && strtoupper((string) $request->user()->site) !== $delivery->arrived_site, 403);
    }

    private function deletePdf(string $url): void
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        Storage::disk('public')->delete(str_starts_with($path, 'storage/') ? substr($path, 8) : $path);
    }
}
