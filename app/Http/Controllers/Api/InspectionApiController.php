<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PresentsInspectionList;
use App\Http\Controllers\Controller;
use App\Models\KategoriInspeksi;
use App\Models\PicaInspeksi;
use App\Services\ImageOptimizerService;
use App\Support\Api\InspectionRegistry;
use App\Support\Api\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionApiController extends Controller
{
    use PresentsInspectionList;

    public function index(Request $request, string $type)
    {
        $config = InspectionRegistry::get($type);
        $site = SiteContext::resolve($request);
        $model = $config['model'];

        $query = $model::query()->with($config['relations'] ?? []);
        SiteContext::apply($query, $config['site_column'], $site);

        // Global server-side search (web DataTable parity). Matches the term in the
        // inspection's own searchable columns OR in the eager-loaded asset relation
        // (inventory/computer/printer/mt) and its user, so a keyword like a partial
        // inventory code (e.g. "hcg-010") is found regardless of which paginated
        // page the record would land on. Own columns are intersected with the
        // model's fillable so a type that lacks a column never breaks the query.
        if ($request->filled('search')) {
            $raw = (string) $request->string('search');
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $fillable = (new $model)->getFillable();
            $ownCols = array_values(array_intersect(
                $config['search_own'] ?? [],
                $fillable
            ));
            $relation = $config['search_relation'] ?? null;
            $relationCols = $config['search_relation_cols'] ?? [];
            $userRelation = $config['search_user_relation'] ?? null;
            $userCols = $config['search_user_cols'] ?? [];

            // Status-label keyword support (visible-label parity). A user may type
            // the human status rendered on the card — "inspected", "not inspected
            // yet", "scrap", "ready used" — and expect it to filter by that label
            // rather than a raw column substring (which would never match the
            // stored codes 'Y' / 'SCRAP' / 'READY_USED'). SCRAP is read from the
            // inspection's OWN inventory/device/asset status column, exactly like
            // the mobile card's _isInspectionScrap(), so the label meaning is
            // identical: SCRAP → "-", non-SCRAP + not Y → "Not Inspected Yet",
            // Y (non-SCRAP) → "INSPECTED".
            $needle = strtolower(trim(preg_replace('/\s+/', ' ', $raw)));
            $statusCols = array_values(array_intersect(
                ['inventory_status', 'device_status', 'asset_status'],
                $fillable
            ));
            $hasInspectionStatus = in_array('inspection_status', $fillable, true);

            $orLike = function ($builder, array $cols) use ($term) {
                $builder->where(function ($inner) use ($cols, $term) {
                    foreach (array_values($cols) as $i => $col) {
                        $i === 0
                            ? $inner->where($col, 'like', $term)
                            : $inner->orWhere($col, 'like', $term);
                    }
                });
            };

            // Row is NOT SCRAP across whichever own status column(s) the type has
            // (mirrors _isInspectionScrap: only the inspection's own column drives
            // the "-" vs "Not Inspected Yet" label).
            $notScrap = function ($q) use ($statusCols) {
                foreach ($statusCols as $col) {
                    $q->where(function ($w) use ($col) {
                        $w->whereNull($col)->orWhere($col, '!=', 'SCRAP');
                    });
                }
            };

            // Row carries the given inventory-status VALUE either on its own status
            // column(s) or on the eager-loaded asset relation (the visible status
            // pill on the card reads the relation's `status`). OR-union → broad
            // match aligned with what the user sees.
            $assetStatusIs = function ($q, string $value) use ($statusCols, $relation) {
                $q->where(function ($w) use ($statusCols, $relation, $value) {
                    foreach ($statusCols as $i => $col) {
                        $i === 0 ? $w->where($col, $value) : $w->orWhere($col, $value);
                    }
                    if ($relation) {
                        $w->orWhereHas($relation, fn ($r) => $r->where('status', $value));
                    }
                });
            };

            $query->where(function ($outer) use (
                $term, $ownCols, $relation, $relationCols, $userRelation, $userCols,
                $orLike, $needle, $hasInspectionStatus, $notScrap, $assetStatusIs
            ) {
                foreach ($ownCols as $col) {
                    $outer->orWhere($col, 'like', $term);
                }
                if ($relation && ! empty($relationCols)) {
                    $outer->orWhereHas($relation, fn ($r) => $orLike($r, $relationCols));
                }
                if ($userRelation && ! empty($userCols)) {
                    $outer->orWhereHas($userRelation, fn ($u) => $orLike($u, $userCols));
                }

                // --- status-label keyword mapping ---
                if ($hasInspectionStatus && $needle === 'inspected') {
                    $outer->orWhere(function ($q) use ($notScrap) {
                        $q->where('inspection_status', 'Y');
                        $notScrap($q);
                    });
                } elseif ($hasInspectionStatus && in_array($needle, [
                    'not inspected', 'not inspected yet', 'belum inspeksi', 'belum diinspeksi',
                ], true)) {
                    $outer->orWhere(function ($q) use ($notScrap) {
                        $q->where(function ($w) {
                            $w->whereNull('inspection_status')->orWhere('inspection_status', '!=', 'Y');
                        });
                        $notScrap($q);
                    });
                }

                if ($needle === 'scrap') {
                    $outer->orWhere(fn ($q) => $assetStatusIs($q, 'SCRAP'));
                } elseif (in_array($needle, ['ready used', 'ready_used'], true)) {
                    $outer->orWhere(fn ($q) => $assetStatusIs($q, 'READY_USED'));
                }
            });
        }

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        if ($request->filled('month')) {
            $query->where('month', $request->integer('month'));
        }

        if ($request->filled('triwulan')) {
            $query->where('triwulan', $request->integer('triwulan'));
        }

        if ($request->filled('inspection_status')) {
            $query->where('inspection_status', $request->string('inspection_status'));
        }

        return response()->json([
            'type' => $type,
            'site' => $site,
            'data' => $query->latest('created_at')
                ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))))
                ->through(fn (Model $inspection) => $this->inspectionResource($inspection)),
        ]);
    }

    public function show(Request $request, string $type, string $id)
    {
        $config = InspectionRegistry::get($type);
        $inspection = $this->authorizedInspectionQuery($request, $config)
            ->with($config['relations'] ?? [])
            ->findOrFail($id);

        $payload = [
            'type' => $type,
            'data' => $this->inspectionResource($inspection),
        ];

        if ($type === 'mobile-tower') {
            $payload['dataKategori'] = KategoriInspeksi::where('kategori_inspeksi', 'MT')
                ->where('parent', 0)
                ->orderBy('urutan', 'ASC')
                ->get();
            $payload['subDataKategori'] = KategoriInspeksi::where('kategori_inspeksi', 'MT')
                ->where('parent', '!=', 0)
                ->orderBy('urutan', 'ASC')
                ->get();
        }

        return response()->json($payload);
    }

    public function update(Request $request, string $type, string $id)
    {
        $config = InspectionRegistry::get($type);
        /** @var Model $inspection */
        $inspection = $this->authorizedInspectionQuery($request, $config)->findOrFail($id);
        SiteContext::authorizeWrite($request, $inspection->getAttribute($config['site_column']));

        $payload = collect($request->only($inspection->getFillable()))
            ->except(['site'])
            ->toArray();

        foreach (['findings_image', 'action_image', 'inspection_image', 'nozle_image'] as $fileField) {
            if ($request->hasFile($fileField)) {
                // Persist the OPTIMIZED image's disk-relative path (e.g.
                // images/uuid.jpg), unchanged from what the service returns — the
                // client builds the display URL as BASE_URL + /storage/ + path.
                $payload[$fileField] = ImageOptimizerService::storeAndOptimize($request->file($fileField), 'images');
            }
        }

        if (in_array('last_edited_by', $inspection->getFillable(), true) && $request->user()) {
            $payload['last_edited_by'] = $request->user()->nrp;
        }

        // ---------------------------------------------------------------------
        // Data-integrity guard — the backend is the LAST line of defense.
        //
        // Every inspection type that carries an `inspection_at` timestamp
        // (laptop / printer / mobile-tower; computer has NO such column and is
        // therefore skipped automatically via the fillable check) must never be
        // marked inspected (inspection_status = 'Y') without that timestamp.
        // The web source of truth always stamps inspection_at server-side when an
        // inspection is performed; the mobile client supplies it in the payload.
        // If the resulting row WOULD be 'Y' with an empty inspection_at, reject
        // with HTTP 422 instead of persisting a corrupt row — this makes the
        // reported ('Y', NULL) state impossible to write from any client.
        // ---------------------------------------------------------------------
        if (in_array('inspection_at', $inspection->getFillable(), true)) {
            $effectiveStatus = array_key_exists('inspection_status', $payload)
                ? (string) $payload['inspection_status']
                : (string) $inspection->getAttribute('inspection_status');

            if (strtoupper(trim($effectiveStatus)) === 'Y') {
                $effectiveAt = array_key_exists('inspection_at', $payload)
                    ? $payload['inspection_at']
                    : $inspection->getAttribute('inspection_at');

                if (blank($effectiveAt)) {
                    throw ValidationException::withMessages([
                        'inspection_at' => 'inspection_at wajib terisi ketika inspection_status = Y.',
                    ]);
                }
            }
        }

        $inspection->update($payload);

        // Mirror web behavior: propagate inventory_status back to parent inventory record
        if (
            isset($payload['inventory_status'])
            && isset($config['inventory_model'])
            && isset($config['inventory_fk'])
        ) {
            $inventoryId = $inspection->getAttribute($config['inventory_fk']);
            if ($inventoryId) {
                $config['inventory_model']::where('id', $inventoryId)
                    ->update(['status' => $payload['inventory_status']]);
            }
        }

        // Mirror web behavior (Inspeksi{Computer,Laptop,Printer,MobileTower}Controller@store):
        // whenever the inspection carries a finding, a PICA record is auto-created/updated
        // (keyed on inspeksi_id) so the finding shows up on the PICA Inspeksi page without a
        // separate manual entry. No finding submitted ⇒ no PICA, exactly like `if ($request->findings)`.
        if ($request->filled('findings')) {
            PicaInspeksi::updateOrCreate(
                ['inspeksi_id' => $inspection->id],
                [
                    'pica_number' => $inspection->getAttribute('pica_number') ?? '0',
                    'temuan' => $inspection->getAttribute('findings'),
                    'tindakan' => $inspection->getAttribute('findings_action'),
                    'due_date' => $inspection->getAttribute('due_date'),
                    'remark' => $inspection->getAttribute('remarks'),
                    'status_pica' => $inspection->getAttribute('findings_status'),
                    'foto_temuan' => $inspection->getAttribute('findings_image'),
                    'foto_tindakan' => $inspection->getAttribute('action_image'),
                    'close_by' => $request->user()?->name,
                    'site' => $inspection->getAttribute($config['site_column']),
                ]
            );
        }

        return response()->json([
            'message' => 'Inspection updated successfully.',
            'data' => $this->inspectionResource($inspection->fresh()),
        ]);
    }

    public function process(Request $request, string $type, string $id)
    {
        $config = InspectionRegistry::get($type);
        $inspection = $this->authorizedInspectionQuery($request, $config)->findOrFail($id);
        $site = strtoupper((string) $inspection->getAttribute($config['site_column']));
        abort_unless(
            in_array($request->user()?->role, ['ict_technician', 'ict_group_leader'], true)
            && strtoupper((string) $request->user()?->site) === $site,
            403,
            'Anda hanya dapat memproses inspeksi site sendiri.'
        );

        $request->merge([
            'inspection_status' => 'Y',
            'inspection_at' => $inspection->isFillable('inspection_at') ? now()->toDateTimeString() : $request->input('inspection_at'),
            'inspector' => $request->user()->name,
        ]);

        return $this->update($request, $type, $id);
    }

    public function approval(Request $request, string $type, string $id)
    {
        $validated = $request->validate([
            'approval_type' => ['required', Rule::in(['accept', 'reject'])],
        ]);
        $config = InspectionRegistry::get($type);
        $inspection = $this->authorizedInspectionQuery($request, $config)->findOrFail($id);
        $site = strtoupper((string) $inspection->getAttribute($config['site_column']));
        abort_unless(
            $request->user()?->role === 'ict_group_leader'
            && strtoupper((string) $request->user()?->site) === $site,
            403,
            'Group Leader hanya dapat melakukan approval untuk site sendiri.'
        );
        abort_unless(in_array($inspection->getAttribute('inspection_status'), ['Y', 'sudah_inspeksi'], true), 422, 'Data ini belum diinspeksi.');

        $inspection->update([
            'approved_by' => $request->user()->name,
            'status_approval' => $validated['approval_type'] === 'accept' ? 'approved' : 'reject',
        ]);

        return response()->json(['data' => $this->inspectionResource($inspection->fresh())]);
    }

    private function authorizedInspectionQuery(Request $request, array $config)
    {
        $model = $config['model'];
        $query = $model::query();

        if (! SiteContext::canAccessAnySite($request)) {
            SiteContext::apply($query, $config['site_column'], SiteContext::resolve($request));
        }

        return $query;
    }
}
