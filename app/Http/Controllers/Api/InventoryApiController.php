<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Aduan;
use App\Models\Department;
use App\Models\UserAll;
use App\Services\ImageOptimizerService;
use App\Support\Api\InventoryRegistry;
use App\Support\Api\SiteContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class InventoryApiController extends Controller
{
    public function index(Request $request, string $type)
    {
        $config = InventoryRegistry::get($type);
        $site = SiteContext::resolve($request);
        $model = $config['model'];

        $query = $model::query()->with($config['relations'] ?? []);
        if (! empty($config['site_column'])) {
            SiteContext::apply($query, $config['site_column'], $site);
        }

        // Global search like the web DataTable: match the term in ANY searchable
        // column (not just code + name). Columns are intersected with the model's
        // fillable so a type that lacks a column never breaks the query.
        if ($request->filled('search')) {
            $raw = (string) $request->string('search');
            $term = '%' . addcslashes($raw, '%_\\') . '%';
            $fillable = (new $model())->getFillable();
            $candidates = array_unique(array_merge(
                [$config['code_column'], $config['name_column']],
                array_intersect([
                    'inventory_number', 'laptop_code', 'computer_code', 'printer_code',
                    'scanner_code', 'cctv_code', 'mt_code', 'device_name', 'laptop_name',
                    'computer_name', 'item_name', 'cctv_name', 'type_mt', 'serial_number',
                    'number_asset_ho', 'assets_category', 'merk', 'model', 'ip_address',
                    'location', 'status', 'condition', 'dept', 'note', 'spesifikasi', 'aplikasi',
                ], $fillable)
            ));

            // The card shows the assigned User/PIC (laptop/computer eager-load
            // `pengguna`), so a keyword like a PIC name must match through that
            // relation too — not just the inventory's own columns.
            $searchUserRelation = in_array('pengguna', $config['relations'] ?? [], true)
                ? 'pengguna'
                : null;
            $userCols = ['username', 'department', 'nrp', 'position'];

            // Status-label keyword parity: the status pill on the card shows the
            // raw code (e.g. READY_USED / SCRAP). A user typing "ready used" (with
            // a space) or "scrap" should still match — LIKE alone misses the
            // underscore form, so map the recognised labels to the stored value.
            $needle = strtolower(trim(preg_replace('/\s+/', ' ', $raw)));
            $statusValue = match ($needle) {
                'ready used', 'ready_used' => 'READY_USED',
                'scrap'                    => 'SCRAP',
                default                    => null,
            };
            $hasStatusCol = in_array('status', $fillable, true);

            $query->where(function ($builder) use ($candidates, $term, $searchUserRelation, $userCols, $statusValue, $hasStatusCol) {
                foreach (array_values($candidates) as $i => $col) {
                    $i === 0
                        ? $builder->where($col, 'like', $term)
                        : $builder->orWhere($col, 'like', $term);
                }
                if ($searchUserRelation) {
                    $builder->orWhereHas($searchUserRelation, function ($u) use ($userCols, $term) {
                        $u->where(function ($inner) use ($userCols, $term) {
                            foreach ($userCols as $i => $col) {
                                $i === 0
                                    ? $inner->where($col, 'like', $term)
                                    : $inner->orWhere($col, 'like', $term);
                            }
                        });
                    });
                }
                if ($statusValue !== null && $hasStatusCol) {
                    $builder->orWhere('status', $statusValue);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'type' => $type,
            'site' => $site,
            'data' => $query->orderBy($config['code_column'])->paginate((int) $request->integer('per_page', 25)),
        ]);
    }

    public function meta(Request $request, string $type)
    {
        $config = InventoryRegistry::get($type);
        $site = SiteContext::resolve($request);

        $departmentQuery = Department::query()->orderBy('department_name');
        $departmentQuery->where('is_site', SiteContext::isHo($site) ? null : 'Y');

        $userAllQuery = UserAll::query()->orderBy('username');
        if (! SiteContext::isHo($site)) {
            $userAllQuery->where('site', $site);
        } else {
            $userAllQuery->where(function ($query) {
                $query->whereNull('site')->orWhere('site', 'HO');
            });
        }

        // CCTV "Switch" dropdown (web CctvController@create passes InvSwitch HO
        // list; value = id, label = inventory_number). Only relevant for cctv but
        // harmless to include for every type.
        $switches = $type === 'cctv'
            ? \App\Models\InvSwitch::query()
                ->where(function ($q) use ($site) {
                    SiteContext::isHo($site)
                        ? $q->whereNull('site')->orWhere('site', 'HO')
                        : $q->where('site', strtoupper((string) $site));
                })
                ->orderBy('inventory_number')
                ->get(['id', 'inventory_number'])
            : collect();

        return response()->json([
            'type' => $type,
            'site' => $site,
            // Drives the create form: 'dept' → show Department selector (laptop/
            // computer); 'company' → show Company selector (AP/switch/printer/
            // wireless/cctv/scanner); 'none' → no auto-code (mobile-tower).
            'code_strategy' => $config['code_strategy'] ?? 'none',
            // Company options are hardcoded PPA/AMM on the web (VueMultiselect).
            'companies' => [['name' => 'PPA'], ['name' => 'AMM']],
            'departments' => $departmentQuery->get(),
            'users' => $userAllQuery->get(['id', 'nrp', 'username', 'department', 'site']),
            // CCTV switch dropdown source.
            'switches' => $switches,
        ]);
    }

    public function show(Request $request, string $type, string $id)
    {
        $config = InventoryRegistry::get($type);
        $model = $config['model'];

        $record = $this->authorizedInventoryQuery($request, $config)
            ->with($config['relations'] ?? [])
            ->findOrFail($id);
        $code = $record->{$config['complaint_column']};

        $response = [
            'type' => $type,
            'data' => $record,
            'complaints' => Aduan::where('inventory_number', $code)->latest()->get(),
        ];

        if (! empty($config['inspection_model'])) {
            $inspectionModel = $config['inspection_model'];
            $response['inspections'] = $inspectionModel::where($config['inspection_foreign_key'], $record->getKey())
                ->latest('created_at')
                ->get();
        }

        return response()->json($response);
    }

    public function store(Request $request, string $type)
    {
        $config = InventoryRegistry::get($type);
        $site = SiteContext::resolve($request) ?? 'HO';
        SiteContext::authorizeWrite($request, $site);

        if (! empty($config['required_fields'])) {
            $request->validate(
                array_fill_keys($config['required_fields'], ['required', 'string'])
            );
        }

        $modelClass = $config['model'];
        /** @var Model $record */
        $record = new $modelClass();

        $payload = $this->extractPayload($request, $record);
        $payload = $this->normalizePayload($payload, $site, $record);
        if (! empty($config['site_column']) && in_array($config['site_column'], $record->getFillable(), true)) {
            $payload[$config['site_column']] = SiteContext::isHo($site) ? 'HO' : $site;
        }

        if (in_array('max_id', $record->getFillable(), true)) {
            $payload['max_id'] = ((int) $modelClass::max('max_id')) + 1;
        }

        if ($request->hasFile('image') && in_array('link_documentation_asset_image', $record->getFillable(), true)) {
            $payload['link_documentation_asset_image'] = ImageOptimizerService::storeAndOptimize($request->file('image'), 'images');
        }

        $created = $modelClass::create($payload);

        return response()->json([
            'message' => 'Inventory created successfully.',
            'data' => $created,
        ], 201);
    }

    public function update(Request $request, string $type, string $id)
    {
        $config = InventoryRegistry::get($type);
        $record = $this->authorizedInventoryQuery($request, $config)->findOrFail($id);
        $payload = $this->extractPayload($request, $record);
        $recordSite = ! empty($config['site_column'])
            ? $record->getAttribute($config['site_column'])
            : SiteContext::resolve($request);
        // Parity with ControllersNew (InvComputerController@update etc.): the web
        // update/destroy do NOT re-check the record's site — they only gate by
        // role (route middleware). Cross-site isolation for non-any-site users is
        // already enforced by authorizedInventoryQuery() (findOrFail → 404). The
        // old per-record-site check could 403 valid edits, so gate by role only.
        SiteContext::authorizeWrite($request);

        $payload = $this->normalizePayload($payload, $recordSite, $record);

        if ($request->hasFile('image') && in_array('link_documentation_asset_image', $record->getFillable(), true)) {
            $payload['link_documentation_asset_image'] = ImageOptimizerService::storeAndOptimize($request->file('image'), 'images');
        }

        $record->update($payload);

        return response()->json([
            'message' => 'Inventory updated successfully.',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy(Request $request, string $type, string $id)
    {
        $config = InventoryRegistry::get($type);
        $record = $this->authorizedInventoryQuery($request, $config)->findOrFail($id);
        // Role-only gate (ControllersNew parity) — see update() note. Soft delete
        // is automatic for models using the SoftDeletes trait (all inventory
        // models except InvMobileTower, which the web also hard-deletes).
        SiteContext::authorizeWrite($request);

        $record->delete();

        return response()->json([
            'message' => 'Inventory deleted.',
        ]);
    }

    public function generateCode(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        $config = InventoryRegistry::get($type);
        $model = $config['model'];
        $codeColumn = $config['code_column'];
        $siteColumn = $config['site_column'] ?? 'site';
        $site = SiteContext::resolve($request) ?? 'HO';
        $sitePrefix = SiteContext::isHo($site) ? 'HO' : strtoupper((string) $site);
        $strategy = $config['code_strategy'] ?? 'none';

        // Scope a query to the active site (HO = null/HO), mirroring the web
        // per-site controllers that hardcode where('site', '<SITE>').
        $scopeSite = function ($query) use ($siteColumn, $site, $sitePrefix) {
            if (SiteContext::isHo($site)) {
                $query->where(fn ($w) => $w->whereNull($siteColumn)->orWhere($siteColumn, 'HO'));
            } else {
                $query->where($siteColumn, $sitePrefix);
            }
            return $query;
        };

        // DEPARTMENT-SCOPED (laptop/computer) — web InvLaptop/InvComputer::
        // generateCode: {SITE}-{NB|PC}-{deptCode}-{seq:3}, sequence per site+dept.
        if ($strategy === 'dept') {
            if (! $request->filled('dept')) {
                return response()->json(
                    ['code' => '', 'message' => 'Pilih department terlebih dahulu.'],
                    422
                );
            }
            $deptInput = trim((string) $request->string('dept'));
            $department = Department::where('department_name', $deptInput)
                ->orWhere('code', $deptInput)
                ->first();
            $deptCode = $department->code ?? $deptInput;

            // Sequence is the trailing number embedded in the code and is scoped
            // per site+dept. max_id is a (roughly) global creation counter, so the
            // record with the highest max_id is NOT necessarily the one with the
            // highest sequence (e.g. MHU/PLT: newest record is -016 while -040
            // already exists). Take the MAX parsed sequence so we always return
            // latest+1.
            $codes = $scopeSite($model::query())
                ->where('dept', $deptCode)
                ->pluck($codeColumn);

            $seq = 0;
            foreach ($codes as $existing) {
                $parts = explode('-', (string) $existing);
                $n = (int) end($parts);
                if ($n > $seq) {
                    $seq = $n;
                }
            }

            $code = $sitePrefix . '-' . ($config['dept_infix'] ?? '') . '-' . $deptCode . '-'
                . str_pad((string) (($seq % 10000) + 1), 3, '0', STR_PAD_LEFT);

            return response()->json(['code' => $code, 'dept' => $deptCode]);
        }

        // COMPANY-SCOPED (AP/switch/printer/wireless/cctv/scanner) — web Inv*::
        // generateCode: {COMPANY}{SITE}{company_code}{seq:3}, sequence per
        // site + code LIKE 'COMPANY%'. Company ∈ {PPA, AMM} (hardcoded in web).
        if ($strategy === 'company') {
            $companyInput = strtoupper(trim(
                (string) ($request->input('company.name') ?? $request->string('company'))
            ));
            $company = in_array($companyInput, ['PPA', 'AMM'], true) ? $companyInput : null;
            if ($company === null) {
                return response()->json(
                    ['code' => '', 'message' => 'Pilih company (PPA / AMM) terlebih dahulu.'],
                    422
                );
            }
            $prefix = $company . $sitePrefix . ($config['company_code'] ?? '');

            // Same reasoning as the dept branch: take the MAX trailing number of
            // every code in scope rather than trusting max_id ordering, so the
            // generated code is always latest+1.
            $codes = $scopeSite($model::query())
                ->where($codeColumn, 'like', $company . '%')
                ->pluck($codeColumn);

            $seq = 0;
            foreach ($codes as $existing) {
                if (preg_match('/(\d+)$/', (string) $existing, $m)) {
                    $n = (int) $m[1];
                    if ($n > $seq) {
                        $seq = $n;
                    }
                }
            }

            $code = $prefix . str_pad((string) (($seq % 10000) + 1), 3, '0', STR_PAD_LEFT);

            return response()->json(['code' => $code, 'company' => $company]);
        }

        // strategy 'none' (mobile-tower) — no company/dept auto-code on the web.
        return response()->json(['code' => '']);
    }

    private function extractPayload(Request $request, Model $record): array
    {
        $fillable = collect($record->getFillable())
            ->reject(fn (string $field) => in_array($field, ['max_id', 'site', 'link_documentation_asset_image'], true))
            ->values()
            ->all();

        return $request->only($fillable);
    }

    private function normalizePayload(array $payload, ?string $site, Model $record): array
    {
        if (in_array('user_alls_id', $record->getFillable(), true) && array_key_exists('user_alls_id', $payload)) {
            $payload['user_alls_id'] = $this->resolveUserAllId($payload['user_alls_id'], $site);
        }

        foreach (['date_of_inventory', 'date_of_deploy', 'inventory_date'] as $dateField) {
            if (! empty($payload[$dateField])) {
                try {
                    $payload[$dateField] = Carbon::parse($payload[$dateField])->toDateString();
                } catch (\Throwable) {
                    // keep as-is if unparseable
                }
            }
        }

        return $payload;
    }

    private function resolveUserAllId(mixed $value, ?string $site): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Parity with the web (InvComputer/InvLaptopController@store/update), which
        // looks the user up by username with NO site scoping. We additionally
        // accept id/nrp because the mobile create form sends the user_alls `id`
        // and the edit form re-sends the stored FK. Critically we must NOT throw:
        // the old site-scoped lookup raised a 422 whenever the stored user's site
        // drifted from the record's site, which broke every computer/laptop edit.
        $user = UserAll::query()
            ->where('id', $value)
            ->orWhere('nrp', $value)
            ->orWhere('username', $value)
            ->first();

        if ($user) {
            return (int) $user->id;
        }

        // Could not resolve by id/nrp/username — the value is not a real user_alls
        // row, so null it out (column is nullable) rather than passing through an
        // id that would trip the foreign key. Never block the write.
        return null;
    }

    private function authorizedInventoryQuery(Request $request, array $config)
    {
        $model = $config['model'];
        $query = $model::query();

        if (! empty($config['site_column']) && ! SiteContext::canAccessAnySite($request)) {
            SiteContext::apply($query, $config['site_column'], SiteContext::resolve($request));
        }

        return $query;
    }
}
