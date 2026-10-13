<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InspeksiAccessPoint;
use App\Models\InspeksiCctv;
use App\Models\InspeksiComputer;
use App\Models\InspeksiLaptop;
use App\Models\InspeksiPanelBoxNetwork;
use App\Models\InspeksiPrinter;
use App\Models\InspeksiScanner;
use App\Models\InspeksiServer;
use App\Models\InspeksiSwitch;
use App\Models\InspeksiWirelless;
use App\Models\PicaInspeksi;
use App\Models\User;
use App\Services\ImageOptimizerService;
use App\Support\Api\SiteContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PicaInspeksiApiController extends Controller
{
    private const DEVICE_TYPES = ['Laptop', 'Computer', 'Mobile Tower', 'Printer', 'Panel Box', 'Access Point', 'Switch', 'Wirelless', 'Scanner', 'Server', 'NVR', 'CCTV'];

    public function meta(Request $request)
    {
        $site = SiteContext::resolve($request);
        $crew = $site !== 'HO'
            ? User::whereIn('role', ['ict_technician', 'ict_group_leader'])->where('site', $site)
            : User::where('role', 'ict_ho')->where('site', 'HO');

        return response()->json([
            'site' => $site,
            'crew' => $crew->pluck('name')->map(fn ($name) => ['name' => $name])->values(),
            'device_types' => collect(self::DEVICE_TYPES)->map(fn (string $type) => ['value' => $type, 'label' => $type])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'device_type' => ['required', 'string', Rule::in(self::DEVICE_TYPES)],
            'site' => ['required', 'string'],
            'status_pica' => ['nullable', 'string'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        $deviceType = $validated['device_type'];
        $site = SiteContext::resolve($request);
        $statusPica = $validated['status_pica'] ?? null;
        $startDate = $validated['startDate'] ?? null;
        $endDate = $validated['endDate'] ?? null;

        $dateColumn = 'created_date';
        $statusColumn = 'findings_status';
        $statusRelation = null;
        $query = match ($deviceType) {
            'Laptop' => InspeksiLaptop::where('site', $site)->whereNotNull('findings_image')->whereHas('inventory')->whereHas('inventory.pengguna')->with('inventory.pengguna'),
            'Computer' => InspeksiComputer::where('site', $site)->whereNotNull('findings_image')->whereHas('computer')->whereHas('computer.pengguna')->with('computer.pengguna'),
            'Printer' => InspeksiPrinter::where('site', $site)->whereNotNull('findings_image')->whereHas('printer')->with('printer'),
            'Mobile Tower' => PicaInspeksi::where('site', $site)->whereHas('inspeksiMt')->whereHas('inspeksiMt.mt')->with(['inspeksiMt', 'inspeksiMt.mt']),
            'Panel Box' => InspeksiPanelBoxNetwork::where('site', $site)->whereNotNull('findings_image')->whereHas('panelBox')->with('panelBox'),
            'Access Point' => InspeksiAccessPoint::where('site', $site)->whereNotNull('findings_image')->whereHas('inventory')->with('inventory'),
            'Switch' => tap(InspeksiSwitch::where('site', $site)->whereNotNull('findings_image')->whereHas('inventory')->with('inventory'), function () use (&$dateColumn): void {
                $dateColumn = 'created_at';
            }),
            'Wirelless' => tap(InspeksiWirelless::where('site', $site)->whereNotNull('findings_image')->whereHas('inventory')->with('inventory'), function () use (&$dateColumn): void {
                $dateColumn = 'created_at';
            }),
            'Scanner' => InspeksiScanner::where('site', $site)->whereNotNull('findings_image')->whereHas('Scanner')->with('Scanner'),
            'Server' => InspeksiServer::where('site', $site)->whereNotNull('findings_image')->whereHas('Server')->with('Server'),
            'NVR' => tap(PicaInspeksi::where('site', $site)->whereHas('inspeksiNvr')->whereHas('inspeksiNvr.invNvr')->with(['inspeksiNvr', 'inspeksiNvr.invNvr']), function () use (&$dateColumn, &$statusColumn): void {
                $dateColumn = 'created_at';
                $statusColumn = 'status_pica';
            }),
            'CCTV' => tap(InspeksiCctv::where('site', $site)->whereHas('findings')->whereHas('cctv')->with(['findings', 'cctv']), function () use (&$dateColumn, &$statusRelation): void {
                $dateColumn = 'created_at';
                $statusRelation = 'findings';
            }),
        };

        if ($startDate && $endDate) {
            $column = in_array($deviceType, ['Mobile Tower', 'NVR'], true) ? 'created_at' : $dateColumn;
            $query->whereBetween($column, [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()]);
        }

        if ($statusPica) {
            if ($statusRelation) {
                $query->whereHas($statusRelation, fn ($finding) => $finding->where('status', $statusPica))
                    ->with([$statusRelation => fn ($finding) => $finding->where('status', $statusPica), 'cctv']);
            } else {
                $query->where(in_array($deviceType, ['Mobile Tower', 'NVR'], true) ? 'status_pica' : $statusColumn, $statusPica);
            }
        } elseif ($statusRelation) {
            $query->whereHas($statusRelation, fn ($finding) => $finding->whereRaw("UPPER(COALESCE(status, '')) NOT IN (?, ?)", ['CLOSE', 'CLOSED']))
                ->with([$statusRelation => fn ($finding) => $finding->whereRaw("UPPER(COALESCE(status, '')) NOT IN (?, ?)", ['CLOSE', 'CLOSED']), 'cctv']);
        } else {
            $column = in_array($deviceType, ['Mobile Tower', 'NVR'], true) ? 'status_pica' : $statusColumn;
            $query->whereRaw("UPPER(COALESCE({$column}, '')) NOT IN (?, ?)", ['CLOSE', 'CLOSED']);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $data = $this->resolveInspection($id, $request->query('device_type'));

        abort_if(! $data, 404, 'Data inspeksi tidak ditemukan');
        $this->authorizeRecordSite($request, $data);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function update(Request $request, string $id)
    {
        SiteContext::authorizeWrite($request, SiteContext::resolve($request));

        $params = $request->all();
        $data = [
            'findings' => $params['temuan'] ?? null,
            'findings_action' => $params['tindakan'] ?? null,
            'due_date' => $params['due_date'] ?? null,
            'findings_status' => $params['findings_status'] ?? null,
            'remarks' => $params['remark'] ?? null,
            'inspector' => $params['inspector'] ?? null,
            'last_edited_by' => $request->user()->nrp,
        ];

        $dataPica = [
            'temuan' => $params['temuan'] ?? null,
            'tindakan' => $params['tindakan'] ?? null,
            'due_date' => $params['due_date'] ?? null,
            'remark' => $params['remark'] ?? null,
            'status_pica' => $params['findings_status'] ?? null,
            'close_by' => $request->user()->name,
            'site' => $request->user()->site,
        ];

        $existing = $this->resolveInspection($id, $params['device_type'] ?? null);
        abort_if(! $existing, 404, 'Data inspeksi tidak ditemukan');
        $this->authorizeRecordSite($request, $existing);

        if ($request->hasFile('image_temuan')) {
            $data['findings_image'] = url('storage/'.ImageOptimizerService::storeAndOptimize($request->file('image_temuan'), 'images'));
            $dataPica['foto_temuan'] = $data['findings_image'];
        }

        if ($request->hasFile('image_tindakan')) {
            $data['action_image'] = url('storage/'.ImageOptimizerService::storeAndOptimize($request->file('image_tindakan'), 'images'));
            $dataPica['foto_tindakan'] = $data['action_image'];
        }

        if ($request->hasFile('image_inspeksi')) {
            $data['inspection_image'] = url('storage/'.ImageOptimizerService::storeAndOptimize($request->file('image_inspeksi'), 'images'));
        }

        if (($params['device_type'] ?? null) === 'NVR') {
            PicaInspeksi::whereKey($existing->getKey())->update($dataPica);

            return response()->json(['message' => 'PICA updated successfully.']);
        }

        if (($params['device_type'] ?? null) === 'CCTV') {
            $finding = $existing->findings()->findOrFail($request->validate([
                'finding_id' => ['required', 'uuid'],
            ])['finding_id']);
            $finding->update([
                'finding' => $params['temuan'] ?? null,
                'action_taken' => $params['tindakan'] ?? null,
                'due_date' => $params['due_date'] ?? null,
                'remark' => $params['remark'] ?? null,
                'status' => $params['findings_status'] ?? null,
                'pic_name' => $params['inspector'] ?? $finding->pic_name,
                'finding_photo' => $dataPica['foto_temuan'] ?? $finding->finding_photo,
                'action_photo' => $dataPica['foto_tindakan'] ?? $finding->action_photo,
            ]);
            if ($finding->pica_number) {
                PicaInspeksi::where('pica_number', $finding->pica_number)->update($dataPica);
            }

            return response()->json(['message' => 'PICA updated successfully.']);
        }

        if (($params['device_type'] ?? null) === 'Mobile Tower') {
            PicaInspeksi::updateOrCreate(['id' => $id], $dataPica);
        } else {
            PicaInspeksi::updateOrCreate(['inspeksi_id' => $id], $dataPica);
        }

        if ($record = InspeksiLaptop::find($id)) {
            $this->authorizeRecordSite($request, $record);
            $record->update($data);
        } elseif ($record = InspeksiPrinter::find($id)) {
            $this->authorizeRecordSite($request, $record);
            $record->update($data);
        } elseif ($record = InspeksiComputer::find($id)) {
            $this->authorizeRecordSite($request, $record);
            $record->update($data);
        } elseif ($record = PicaInspeksi::with('inspeksiMt.mt')->find($id)) {
            $this->authorizeRecordSite($request, $record);
            $record->inspeksiMt?->mt?->update([
                'inspection_remark' => $params['remark'] ?? null,
            ]);
        } else {
            abort(404, 'Data inspeksi tidak ditemukan');
        }

        return response()->json([
            'message' => 'PICA updated successfully.',
        ]);
    }

    private function resolveInspection(string $id, ?string $deviceType = null): mixed
    {
        if ($deviceType === 'NVR') {
            return $this->resolveNvrPica($id);
        }

        if ($deviceType === 'CCTV') {
            return $this->resolveCctvPica($id);
        }

        if ($data = InspeksiLaptop::with('inventory.pengguna')->find($id)) {
            $inv = $data->inventory;
            $data->device_code = $inv->laptop_code ?? '-';
            $data->device_name = strtoupper($inv->laptop_name ?? '-');
            $data->device_sn = $inv->serial_number ?? '-';
            $data->device_condition = $inv->condition ?? '-';
            $data->device_asset_ho = $inv->number_asset_ho ?? '-';
            $data->device_spesifikasi = $inv->spesifikasi ?? '-';
            $data->device_status_inventory = $inv->status ?? '-';
            $data->device_note = $inv->note ?? '-';
            $data->device_pengguna = $inv->pengguna->username ?? '-';
            $data->device_dept = $inv->pengguna->department ?? '-';
            $data->device_ip_address = $inv->ip_address ?? '-';
            $data->device_location = $inv->location ?? '-';
            $data->device_type = 'Laptop';

            return $data;
        }

        if ($data = InspeksiPrinter::with('printer')->find($id)) {
            $prt = $data->printer;
            $data->device_code = $prt->printer_code ?? '-';
            $data->device_name = strtoupper($prt->item_name ?? '-');
            $data->device_sn = $prt->serial_number ?? '-';
            $data->device_condition = $data->condition ?? '-';
            $data->device_asset_ho = $prt->asset_ho_number ?? '-';
            $data->device_spesifikasi = '-';
            $data->device_status_inventory = $prt->status ?? '-';
            $data->device_note = $prt->note ?? '-';
            $data->device_pengguna = $prt->division ?? '-';
            $data->device_dept = $prt->pengguna->department ?? '-';
            $data->device_ip_address = $prt->ip_address ?? '-';
            $data->device_location = $prt->location ?? '-';
            $data->device_type = 'Printer';

            return $data;
        }

        if ($data = PicaInspeksi::with('inspeksiMt.mt')->whereHas('inspeksiMt')->find($id)) {
            $mt = $data->inspeksiMt;
            $data->device_code = $mt->mt->inventory_number ?? '-';
            $data->device_name = strtoupper($mt->mt->mt_code ?? '-');
            $data->device_sn = '-';
            $data->device_condition = $mt->condition ?? '-';
            $data->device_asset_ho = '-';
            $data->device_spesifikasi = '-';
            $data->device_status_inventory = $mt->mt->status ?? '-';
            $data->device_note = $mt->mt->note ?? '-';
            $data->device_pengguna = '-';
            $data->device_dept = '-';
            $data->device_ip_address = '-';
            $data->device_location = $mt->mt->location ?? '-';
            $data->device_type = 'Mobile Tower';
            $data->findings = $data->temuan;
            $data->findings_image = $data->foto_temuan;
            $data->findings_action = $data->tindakan;
            $data->action_image = $data->foto_tindakan;
            $data->findings_status = $data->status_pica;
            $data->remarks = $data->remark;
            $data->inspector = $mt->pic;
            $data->inspection_image = $mt->inspection_image;

            return $data;
        }

        if ($data = InspeksiComputer::with('computer.pengguna')->find($id)) {
            $cmp = $data->computer;
            $data->device_code = $cmp->computer_code ?? '-';
            $data->device_name = strtoupper($cmp->computer_name ?? '-');
            $data->device_sn = $cmp->serial_number ?? '-';
            $data->device_condition = $cmp->condition ?? '-';
            $data->device_asset_ho = $cmp->number_asset_ho ?? '-';
            $data->device_spesifikasi = $cmp->spesifikasi ?? '-';
            $data->device_status_inventory = $cmp->status ?? '-';
            $data->device_note = $cmp->note ?? '-';
            $data->device_pengguna = $cmp->pengguna->username ?? '-';
            $data->device_dept = $cmp->pengguna->department ?? '-';
            $data->device_ip_address = $cmp->ip_address ?? '-';
            $data->device_location = $cmp->location ?? '-';
            $data->device_type = 'Computer';

            return $data;
        }

        if ($data = $this->resolveNvrPica($id)) {
            return $data;
        }

        if ($data = $this->resolveCctvPica($id)) {
            return $data;
        }

        return null;
    }

    private function resolveNvrPica(string $id): ?PicaInspeksi
    {
        $data = PicaInspeksi::with(['inspeksiNvr', 'inspeksiNvr.invNvr'])
            ->whereHas('inspeksiNvr')->find($id);
        if (! $data) {
            return null;
        }

        $inspection = $data->inspeksiNvr;
        $inventory = $inspection?->invNvr;
        $data->device_code = $inventory?->inventory_number ?? '-';
        $data->device_name = strtoupper($inventory?->nvr_name ?? 'NVR');
        $data->device_sn = $inventory?->serial_number ?? '-';
        $data->device_condition = $inspection?->kondisi ?? '-';
        $data->device_asset_ho = $inventory?->number_asset_ho ?? '-';
        $data->device_status_inventory = $inventory?->status ?? '-';
        $data->device_note = $inventory?->remark ?? '-';
        $data->device_ip_address = $inventory?->ip_address ?? '-';
        $data->device_location = $inventory?->location ?? '-';
        $data->device_type = 'NVR';
        $data->findings = $data->temuan;
        $data->findings_image = $data->foto_temuan;
        $data->findings_action = $data->tindakan;
        $data->action_image = $data->foto_tindakan;
        $data->findings_status = $data->status_pica;
        $data->remarks = $data->remark;
        $data->inspector = $inspection?->inspector_name;

        return $data;
    }

    private function resolveCctvPica(string $id): ?InspeksiCctv
    {
        $data = InspeksiCctv::with(['cctv', 'findings'])->find($id);
        if (! $data) {
            return null;
        }

        $inventory = $data->cctv;
        $data->device_code = $inventory?->inventory_number ?? $inventory?->cctv_code ?? '-';
        $data->device_name = strtoupper($inventory?->cctv_name ?? 'CCTV');
        $data->device_condition = $data->kondisi ?? '-';
        $data->device_asset_ho = $inventory?->number_asset_ho ?? '-';
        $data->device_status_inventory = $inventory?->status ?? '-';
        $data->device_note = $inventory?->remark ?? '-';
        $data->device_ip_address = $inventory?->ip_address ?? '-';
        $data->device_location = $inventory?->location ?? '-';
        $data->device_type = 'CCTV';
        $active = $data->findings->first(fn ($finding) => ! in_array(strtoupper((string) $finding->status), ['CLOSE', 'CLOSED'], true));
        if ($active) {
            $data->findings_text = $active->finding;
            $data->findings_image = $active->finding_photo;
            $data->findings_action = $active->action_taken;
            $data->action_image = $active->action_photo;
            $data->findings_status = $active->status;
            $data->due_date = $active->due_date;
        }

        return $data;
    }

    private function authorizeRecordSite(Request $request, mixed $record): void
    {
        if (SiteContext::canAccessAnySite($request)) {
            return;
        }

        $recordSite = strtoupper((string) ($record->site ?? $record->inspeksiMt?->site ?? null));
        abort_if($recordSite !== SiteContext::resolve($request), 404);
    }
}
