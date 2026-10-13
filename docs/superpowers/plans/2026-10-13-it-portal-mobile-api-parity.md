# IT Portal Mobile API Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete and verify the Flutter-facing APIs in `ppa-api-new` for supported IT-PORTAL-HO Inventory, Inspeksi, Operation, VHMS, Performance, and PICA flows, while reporting source/controller gaps without inventing behavior.

**Architecture:** Treat the portal routes/controllers as the behavior source, preserve the target project's established JSON, `SiteContext`, registry, specialized-controller, and Sanctum patterns, and add only evidence-backed endpoints. Add focused feature tests for each gap and produce a final parity matrix/report.

**Tech Stack:** PHP 8.2, Laravel 11, Sanctum, PHPUnit 11, MySQL-compatible Eloquent models.

**Spec:** `docs/superpowers/specs/2026-10-13-it-portal-mobile-api-parity-design.md`

## Global Constraints

- Do not create behavior when the corresponding portal controller or flow cannot be found; list it in the report.
- Do not modify IT-PORTAL-HO.
- Preserve existing route contracts unless they are unusable or incomplete for the documented portal flow.
- Enforce authentication, role permissions, and site isolation server-side.
- Use JSON and mobile-consumable file URLs.
- Follow red-green-refactor for production changes.
- Preserve unrelated user changes.

---

### Task 1: Build the parity inventory and executable route audit

**Files:**
- Create: `tests/Feature/Api/ApiRouteIntegrityTest.php`
- Create: `docs/API_PARITY_REPORT.md`
- Inspect: `../IT-PORTAL-HO-PALING-BARU-9-2026/routes/web.php`
- Inspect: `../IT-PORTAL-HO-PALING-BARU-9-2026/resources/js/Components/inventory/mobileNavigation.mjs`
- Inspect: `routes/api.php`

**Interfaces:**
- Consumes: portal navigation entries, portal controller references, target API routes/controllers.
- Produces: a menu-to-endpoint matrix and a test that identifies route actions whose classes or methods do not exist.

- [ ] **Step 1: Write the failing route-integrity test**

Create a feature test that loads API routes, checks each controller action with `class_exists` and `method_exists`, and explicitly records known missing source/API controller references instead of silently accepting them.

- [ ] **Step 2: Run the test and observe the existing missing `Api\\VhmsUnitController` failure**

Run: `php artisan test tests/Feature/Api/ApiRouteIntegrityTest.php --filter=api_route_actions_are_resolvable`

Expected: FAIL naming `App\\Http\\Controllers\\Api\\VhmsUnitController`.

- [ ] **Step 3: Map all in-scope portal menus**

Record Inventory, Inspeksi, Operation, VHMS, Performance, and PICA menu rows with source controller, existing API, status, and notes in `docs/API_PARITY_REPORT.md`.

- [ ] **Step 4: Keep missing-controller work report-only**

Do not implement a controller whose authoritative source behavior cannot be established. Mark its exact routes and impact in the report.

### Task 2: Add NVR inspection API parity

**Files:**
- Create: `tests/Feature/Api/NvrInspectionApiTest.php`
- Create: `app/Http/Controllers/Api/NvrInspectionController.php`
- Modify: `routes/api.php`
- Reference: `../IT-PORTAL-HO-PALING-BARU-9-2026/app/Http/Controllers/InspeksiNvrController.php`
- Reference: `app/Models/InspeksiNvr.php`

**Interfaces:**
- Consumes: authenticated request, effective site, NVR inspection identifier, multipart inspection/approval fields.
- Produces: `GET /api/inspections/nvrs`, `GET /api/inspections/nvrs/{inspection}`, `POST /api/inspections/nvrs/{inspection}/process`, and `POST /api/inspections/nvrs/{inspection}/approval`.

- [ ] **Step 1: Write failing feature tests**

Cover route existence, authenticated site-scoped list/detail, technician processing, invalid payload 422, group-leader approval, and unauthorized cross-site access.

- [ ] **Step 2: Run the focused tests and confirm failure because routes/controller do not exist**

Run: `php artisan test tests/Feature/Api/NvrInspectionApiTest.php --compact`

- [ ] **Step 3: Implement the minimum NVR API controller and routes**

Mirror portal validation, field mapping, findings/evidence storage, inspection status transitions, approval prerequisites, and role/site restrictions. Use transactions for multi-model writes.

- [ ] **Step 4: Run the focused tests and refactor only after green**

Run: `php artisan test tests/Feature/Api/NvrInspectionApiTest.php --compact`

### Task 3: Add CCTV inspection API parity

**Files:**
- Create: `tests/Feature/Api/CctvInspectionApiTest.php`
- Create: `app/Http/Controllers/Api/CctvInspectionController.php`
- Modify: `routes/api.php`
- Reference: `../IT-PORTAL-HO-PALING-BARU-9-2026/app/Http/Controllers/InspeksiCctvController.php`
- Reference: `app/Models/InspeksiCctv.php`

**Interfaces:**
- Consumes: authenticated request, effective site, CCTV inspection identifier, nested findings and multipart evidence.
- Produces: `GET /api/inspections/cctv`, `GET /api/inspections/cctv/{inspection}`, `POST /api/inspections/cctv/{inspection}/process`, and `POST /api/inspections/cctv/{inspection}/approval`.

- [ ] **Step 1: Write failing feature tests**

Cover route existence, list/detail response, nested findings validation/persistence, approval state, and site isolation.

- [ ] **Step 2: Run the focused tests and confirm missing-route failure**

Run: `php artisan test tests/Feature/Api/CctvInspectionApiTest.php --compact`

- [ ] **Step 3: Implement the minimum CCTV API controller and routes**

Mirror the portal controller's parent inspection fields, finding rows, file behavior, status transitions, and approval rules in a transaction.

- [ ] **Step 4: Run focused tests and refactor after green**

Run: `php artisan test tests/Feature/Api/CctvInspectionApiTest.php --compact`

### Task 4: Close evidence-backed gaps in existing domain APIs

**Files:**
- Modify: relevant files under `app/Http/Controllers/Api/`
- Modify: `app/Support/Api/InventoryRegistry.php` or `InspectionRegistry.php` only when equivalent generic behavior is proven.
- Create/Modify: domain tests under `tests/Feature/Api/`
- Modify: `docs/API_PARITY_REPORT.md`

**Interfaces:**
- Consumes: portal controller behavior and current target implementation.
- Produces: parity for existing Inventory, Operation, supported VHMS routes, Performance, and PICA APIs without speculative features.

- [ ] **Step 1: Compare validation, authorization, transitions, uploads, and side effects for every mapped endpoint**

Document each difference before changing production code.

- [ ] **Step 2: For each confirmed gap, write one focused failing test**

Name the specific portal behavior the test protects and run it to observe the correct failure.

- [ ] **Step 3: Implement the smallest parity fix**

Preserve route and response compatibility, use `SiteContext`, and wrap multi-write operations in a transaction.

- [ ] **Step 4: Re-run each domain test after its change**

Do not mark a row complete without fresh test or static evidence.

### Task 5: Verify and publish the final report

**Files:**
- Modify: `docs/API_PARITY_REPORT.md`

**Interfaces:**
- Consumes: completed route map, diffs, syntax checks, focused tests, and environment diagnostics.
- Produces: exact endpoint list, implemented changes, missing controllers, verification results, and blockers.

- [ ] **Step 1: Run syntax and route verification**

Run PHP lint on every modified PHP file and `php artisan route:list --path=api`. If known missing report-only controllers prevent route listing, record the exact exception and independently verify all newly added route actions.

- [ ] **Step 2: Run focused API tests**

Run every new test file. Use the configured test database; report database connection failures separately from assertion failures.

- [ ] **Step 3: Run the feasible full suite**

Run: `php artisan test --compact`

Read the full result and do not claim success for tests blocked by MySQL or unrelated existing failures.

- [ ] **Step 4: Review the diff and complete the report**

List every changed file and endpoint, known missing controller, source-flow exception, test count, failure, and environment blocker.
