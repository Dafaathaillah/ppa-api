# Unified Inspection and PICA Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every Flutter inspection device a secure, consistent inspection, approval, and bidirectional PICA lifecycle.

**Architecture:** Add reusable site-scoped querying and PICA synchronization to the established inspection controller layer, then make generic and specialized controllers consume those shared rules. Preserve existing routes and response shapes while adding missing process/approval routes for generic devices and explicit PICA device resolution.

**Tech Stack:** PHP 8.2, Laravel, Sanctum, Eloquent, PHPUnit feature tests, SQLite test database.

**Spec:** `docs/superpowers/specs/2026-10-13-unified-inspection-pica-flow-design.md`

## Global Constraints

- Preserve current Flutter-compatible routes and response keys.
- Do not implement the missing `App\Http\Controllers\Api\VhmsUnitController`.
- Do not add database migrations.
- Store uploaded image paths as disk-relative paths.
- Normalize accepted approvals to `approved` and rejected approvals to `reject`.
- Apply TDD: every production behavior change follows a failing feature test.

---

### Task 1: Site-scoped reads for specialized inspections

**Files:**
- Modify: `app/Http/Controllers/Api/Concerns/ProcessesAssetInspection.php`
- Modify: `app/Http/Controllers/Api/AbstractSimpleAssetInspectionController.php`
- Modify: `app/Http/Controllers/Api/MobileTowerInspectionController.php`
- Modify: `app/Http/Controllers/Api/PanelBoxInspectionController.php`
- Modify: `app/Http/Controllers/Api/TowerInspectionController.php`
- Test: `tests/Feature/Api/InspectionSiteAuthorizationTest.php`

**Interfaces:**
- Consumes: `SiteContext::canAccessAnySite(Request)` and `SiteContext::resolve(Request)`.
- Produces: reusable `scopeInspectionAccess(Builder, Request, ?string): Builder` and authenticated `show(Request, string)` behavior.

- [ ] **Step 1: Write failing cross-site list and detail tests**

Create records for two sites, authenticate a site technician, assert their list omits the other site and its detail returns `404` for one simple controller and each custom-controller family.

- [ ] **Step 2: Run the authorization test and verify it fails for cross-site exposure**

Run: `php artisan test tests/Feature/Api/InspectionSiteAuthorizationTest.php --compact`

- [ ] **Step 3: Implement site-scoped query helpers and use them in list/detail actions**

Apply the authenticated site unless the user has cross-site permission. Treat a supplied `site` filter as a narrowing filter only.

- [ ] **Step 4: Run the authorization test and verify it passes**

Run: `php artisan test tests/Feature/Api/InspectionSiteAuthorizationTest.php --compact`

### Task 2: Consistent PICA lifecycle from inspection processing

**Files:**
- Create: `app/Services/InspectionPicaSynchronizer.php`
- Modify: `app/Http/Controllers/Api/Concerns/ProcessesAssetInspection.php`
- Modify: `app/Http/Controllers/Api/AbstractSimpleAssetInspectionController.php`
- Modify: `app/Http/Controllers/Api/MobileTowerInspectionController.php`
- Modify: `app/Http/Controllers/Api/PanelBoxInspectionController.php`
- Modify: `app/Http/Controllers/Api/TowerInspectionController.php`
- Modify: `app/Http/Controllers/Api/AbstractVideoInspectionController.php`
- Test: `tests/Feature/Api/InspectionPicaLifecycleTest.php`

**Interfaces:**
- Produces: `InspectionPicaSynchronizer::sync(Model $inspection, array $attributes): ?PicaInspeksi` and `remove(Model $inspection): void`.
- The attributes include `pica_number`, `temuan`, `tindakan`, `due_date`, `remark`, `status_pica`, `foto_temuan`, `foto_tindakan`, `close_by`, and `site`.

- [ ] **Step 1: Write failing tests for create, update without duplication, close, and remove**

Assert the PICA row count, linked `inspeksi_id`, status, action fields, and cleared inspection PICA fields.

- [ ] **Step 2: Run the lifecycle tests and verify the expected failures**

Run: `php artisan test tests/Feature/Api/InspectionPicaLifecycleTest.php --compact`

- [ ] **Step 3: Implement the synchronizer and replace duplicated controller persistence**

Use `updateOrCreate(['inspeksi_id' => $inspection->getKey()], $attributes)` for findings and delete by `inspeksi_id` when findings are empty. Keep CCTV's multi-finding relation behavior specialized.

- [ ] **Step 4: Run lifecycle and existing video inspection tests**

Run: `php artisan test tests/Feature/Api/InspectionPicaLifecycleTest.php tests/Feature/Api/NvrInspectionApiTest.php tests/Feature/Api/CctvInspectionApiTest.php --compact`

### Task 3: Complete Laptop, Computer, Printer and approval parity

**Files:**
- Modify: `app/Http/Controllers/Api/InspectionApiController.php`
- Modify: `app/Support/Api/InspectionRegistry.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/MobileTowerInspectionController.php`
- Test: `tests/Feature/Api/GenericInspectionWorkflowTest.php`

**Interfaces:**
- Produces: `POST /api/inspections/{type}/{id}/process` and `POST /api/inspections/{type}/{id}/approval` for registry-backed types.
- Produces normalized `status_approval` values of `approved` or `reject`.

- [ ] **Step 1: Write failing process, approval, role, and compatibility tests**

Exercise Laptop, Computer, and Printer with their registry configuration; assert existing PUT/PATCH remains functional and new endpoints enforce site/role rules.

- [ ] **Step 2: Run the generic workflow tests and verify missing endpoints fail**

Run: `php artisan test tests/Feature/Api/GenericInspectionWorkflowTest.php --compact`

- [ ] **Step 3: Implement registry-backed process/approval and route ordering**

Reuse current fillable filtering, upload handling, inventory propagation, and the shared PICA synchronizer. Add routes before the generic detail route.

- [ ] **Step 4: Normalize Mobile Tower approval and run workflow tests**

Run: `php artisan test tests/Feature/Api/GenericInspectionWorkflowTest.php --compact`

### Task 4: Bidirectional PICA updates for every device

**Files:**
- Modify: `app/Http/Controllers/Api/PicaInspeksiApiController.php`
- Test: `tests/Feature/Api/PicaAllDevicesApiTest.php`
- Test: `tests/Feature/Api/PicaVideoInspectionApiTest.php`

**Interfaces:**
- Produces explicit device-type-to-model/relation mapping for all thirteen device types.
- PICA update requires `device_type`, resolves only that device type, validates site access, and transactionally updates both sides.

- [ ] **Step 1: Write failing meta, list, detail, and update synchronization tests**

Cover Panel Box, Access Point, Switch, Wireless, Scanner, Server, and Tower; retain Laptop, Computer, Printer, Mobile Tower, NVR, and CCTV regression coverage.

- [ ] **Step 2: Run the all-device PICA tests and verify unsupported updates fail**

Run: `php artisan test tests/Feature/Api/PicaAllDevicesApiTest.php --compact`

- [ ] **Step 3: Implement explicit resolver/update mappings and transactional persistence**

Add Tower to metadata and avoid probing unrelated tables. Map standard PICA input names to each inspection's common finding columns and preserve device-specific relations.

- [ ] **Step 4: Run all PICA tests**

Run: `php artisan test tests/Feature/Api/PicaAllDevicesApiTest.php tests/Feature/Api/PicaVideoInspectionApiTest.php --compact`

### Task 5: Final verification and report

**Files:**
- Modify: `docs/API_PARITY_REPORT.md`

**Interfaces:**
- Consumes all completed inspection endpoints and tests.
- Produces an updated implementation report with routes, device coverage, validation evidence, and remaining unrelated blockers.

- [ ] **Step 1: Run PHP syntax validation on changed PHP files**

Run `php -l` for every changed PHP source and test file; expect no syntax errors.

- [ ] **Step 2: Run the focused inspection suite**

Run: `php artisan test tests/Feature/Api --filter=Inspection --compact` plus all PICA feature tests; expect zero failures.

- [ ] **Step 3: Run the complete test suite**

Run: `php artisan test --compact`; record any unrelated existing failures precisely.

- [ ] **Step 4: Review routes statically and update the report**

Because `route:list` is blocked by the out-of-scope missing VHMS controller, confirm inspection route order from `routes/api.php` and document that limitation.

- [ ] **Step 5: Inspect `git diff --check` and final status**

Expect no whitespace errors and no unrelated files in the change set.
