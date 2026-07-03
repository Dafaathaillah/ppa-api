<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriInspeksi;
use App\Models\PicaInspeksi;
use App\Services\ImageOptimizerService;
use App\Support\Api\InspectionRegistry;
use App\Support\Api\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class InspectionApiController extends Controller
{
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
            $raw  = (string) $request->string('search');
            $term = '%' . addcslashes($raw, '%_\\') . '%';
            $ownCols = array_values(array_intersect(
                $config['search_own'] ?? [],
                (new $model())->getFillable()
            ));
            $relation     = $config['search_relation'] ?? null;
            $relationCols = $config['search_relation_cols'] ?? [];
            $userRelation = $config['search_user_relation'] ?? null;
            $userCols     = $config['search_user_cols'] ?? [];

            $orLike = function ($builder, array $cols) use ($term) {
                $builder->where(function ($inner) use ($cols, $term) {
                    foreach (array_values($cols) as $i => $col) {
                        $i === 0
                            ? $inner->where($col, 'like', $term)
                            : $inner->orWhere($col, 'like', $term);
                    }
                });
            };

            $query->where(function ($outer) use ($term, $ownCols, $relation, $relationCols, $userRelation, $userCols, $orLike) {
                foreach ($ownCols as $col) {
                    $outer->orWhere($col, 'like', $term);
                }
                if ($relation && ! empty($relationCols)) {
                    $outer->orWhereHas($relation, fn ($r) => $orLike($r, $relationCols));
                }
                if ($userRelation && ! empty($userCols)) {
                    $outer->orWhereHas($userRelation, fn ($u) => $orLike($u, $userCols));
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
            'data' => $query->latest('created_at')->paginate((int) $request->integer('per_page', 25)),
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
            'data' => $inspection,
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
                    'pica_number'  => $inspection->getAttribute('pica_number') ?? '0',
                    'temuan'       => $inspection->getAttribute('findings'),
                    'tindakan'     => $inspection->getAttribute('findings_action'),
                    'due_date'     => $inspection->getAttribute('due_date'),
                    'remark'       => $inspection->getAttribute('remarks'),
                    'status_pica'  => $inspection->getAttribute('findings_status'),
                    'foto_temuan'  => $inspection->getAttribute('findings_image'),
                    'foto_tindakan' => $inspection->getAttribute('action_image'),
                    'close_by'     => $request->user()?->name,
                    'site'         => $inspection->getAttribute($config['site_column']),
                ]
            );
        }

        return response()->json([
            'message' => 'Inspection updated successfully.',
            'data' => $inspection->fresh(),
        ]);
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
