<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListUnit;
use App\Models\RootCauseProblem;
use App\Services\VhmsApiUnitSynchronizer;
use App\Support\OperationalShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VhmsUnitController extends Controller
{
    private const SITE_SELECTOR_NRP = '23002073';

    public function options(Request $request, string $site): JsonResponse
    {
        $site = $this->authorizeSite($request, $site);

        return response()->json([
            'site' => $site,
            'sites' => $this->canSelectAnySite($request) ? VhmsApiUnitSynchronizer::SITES : [$site],
            'statuses' => ['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'],
            'root_causes' => RootCauseProblem::query()
                ->whereRaw('UPPER(TRIM(kategori_name)) = ?', ['VHMS'])
                ->orderBy('root_cause_problem')
                ->get(['id_cause', 'root_cause_problem']),
        ]);
    }

    public function store(Request $request, string $site): JsonResponse
    {
        $site = $this->authorizeSite($request, $site);
        $validated = $this->validateUnit($request, true, $site);
        $unit = ListUnit::create([
            ...$validated,
            'site' => $site,
            'last_update_at' => now(),
            'last_update_by' => $request->user()->name ?? 'System',
        ]);

        return response()->json(['message' => 'Unit VHMS created.', 'data' => $unit], 201);
    }

    public function show(Request $request, string $site, string $unit): JsonResponse
    {
        $site = $this->authorizeSite($request, $site);

        return response()->json(['data' => $this->unitForSite($site, $unit)->load('repairs')]);
    }

    public function update(Request $request, string $site, string $unit): JsonResponse
    {
        $site = $this->authorizeSite($request, $site);
        $record = $this->unitForSite($site, $unit);
        $validated = $this->validateUnit($request, false, $site, $record->getKey());

        DB::transaction(function () use ($request, $record, $validated): void {
            $isReadyUsed = ($validated['status'] ?? $record->status) === 'READY_USED';
            if (strtolower(trim((string) $record->status_vhms)) === 'not_update') {
                $rootCause = RootCauseProblem::find($validated['root_cause_problem_id'] ?? 1002);
                $operational = OperationalShift::from(now());
                $record->repairs()->create([
                    'vhms_not_update_case_id' => null,
                    'status_vhms' => $record->status_vhms ?: 'unknown',
                    'status' => $validated['status'] ?? $record->status,
                    'shift' => $operational->shift,
                    'operational_date' => $operational->operationalDate,
                    'repair_started_at' => $isReadyUsed ? null : ($validated['repair_started_at'] ?? null),
                    'repair_ended_at' => $isReadyUsed ? null : ($validated['repair_ended_at'] ?? null),
                    'root_cause_problem_id' => $rootCause?->getKey() ?? 1002,
                    'root_cause' => $rootCause?->root_cause_problem ?? 'Un-Identified',
                    'action' => $validated['action'] ?? null,
                    'repair_note' => $validated['remark'] ?? null,
                    'checking_by' => $validated['checking_by'] ?? $request->user()->name,
                    'updated_by' => $request->user()->name,
                ]);
            }

            $record->update([
                ...array_intersect_key($validated, array_flip(['status', 'tipe_wirelless', 'ip_wirelless', 'mac_wirelless', 'remark'])),
                'last_update_at' => now(),
                'last_update_by' => $request->user()->name ?? 'System',
            ]);
        });

        return response()->json(['message' => 'Unit VHMS updated.', 'data' => $record->fresh('repairs')]);
    }

    public function destroy(Request $request, string $site, string $unit): JsonResponse
    {
        $site = $this->authorizeSite($request, $site);
        abort_unless($request->user()->role === 'ict_developer', 403);
        $this->unitForSite($site, $unit)->delete();

        return response()->json(status: 204);
    }

    private function validateUnit(Request $request, bool $creating, string $site, mixed $ignoreId = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type' => [$required, 'string', 'max:100'],
            'model' => [$required, 'string', 'max:100'],
            'sn' => [$required, 'string', 'max:100', Rule::unique('list_unit', 'sn')->ignore($ignoreId)],
            'cn' => [$required, 'string', 'max:100', Rule::unique('list_unit', 'cn')->where(fn ($q) => $q->where('site', $site))->ignore($ignoreId)],
            'status' => [$required, Rule::in(['READY_USED', 'READY_STANDBY', 'BREAKDOWN', 'SCRAP'])],
            'tipe_wirelless' => ['nullable', 'string', 'max:100'],
            'ip_wirelless' => ['nullable', 'ip'],
            'mac_wirelless' => ['nullable', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:5000'],
            'repair_started_at' => ['nullable', 'date'],
            'repair_ended_at' => ['nullable', 'date', 'after_or_equal:repair_started_at'],
            'root_cause_problem_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:5000'],
            'checking_by' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function authorizeSite(Request $request, string $site): string
    {
        $site = strtoupper(trim($site));
        abort_unless(in_array($site, VhmsApiUnitSynchronizer::SITES, true), 404);
        abort_unless($this->canSelectAnySite($request) || strtoupper(trim((string) $request->user()->site)) === $site, 403);

        return $site;
    }

    private function canSelectAnySite(Request $request): bool
    {
        return $request->user()->role === 'ict_developer' || (string) $request->user()->nrp === self::SITE_SELECTOR_NRP;
    }

    private function unitForSite(string $site, string $id): ListUnit
    {
        return ListUnit::query()->whereRaw('UPPER(TRIM(site)) = ?', [$site])->findOrFail($id);
    }
}
