# IT Portal Mobile API Parity Design

**Date:** 2026-10-13  
**Source of truth:** `IT-PORTAL-HO-PALING-BARU-9-2026`  
**Target:** `ppa-api-new`  
**Consumer:** Flutter mobile application

## Goal

Make `ppa-api-new` expose a secure, JSON-first API for every supported flow under Inventory, Inspeksi, Operation, VHMS, Performance, and PICA in IT-PORTAL-HO. A portal controller or flow that cannot be found will be reported and will not be recreated from assumptions.

## Scope and parity rule

For each portal menu, trace the route, controller, request validation, model relationships, role/site restrictions, state transitions, file handling, and database side effects. Existing APIs remain in place when they already match this behavior. Missing or incomplete APIs are added or repaired only when the corresponding portal implementation exists.

The audit covers:

- Inventory: user devices, network devices, supporting devices, Tab OVA, Starlink, Server, NVR, CCTV, Mobile Tower, Panel Box, Tower, and Stock Opname.
- Inspeksi: schedule, chart, laptop, computer, mobile tower, panel box, access point, switch, wireless, printer, scanner, server, tower, NVR, CCTV, and PICA.
- Operation: asset transfer, monitoring jobs, job assignment, unscheduled jobs, daily server monitoring, and password bank.
- VHMS: unit list and CRUD, not-ready/problem flows, repair actions, evidence import/export, and history.
- Performance: KPI response time, KPI inspeksi, KPI VHMS, and complaint analysis where exposed by the portal.
- PICA: supported inspection types, detail, assignment/update, evidence, status, and site authorization.

Out of scope:

- Inventing business behavior for a missing portal controller.
- Changing portal behavior or its user interface.
- Building the Flutter client itself; its source is not present in this workspace.
- Unrelated refactoring or data migrations without evidence that an API flow requires them.

## API contract

All protected endpoints use Sanctum authentication. Collection endpoints support pagination and the filters required by the equivalent portal screen. Detail endpoints return one record with the relationships required to render the corresponding mobile detail screen. Form screens receive selectable values from explicit `meta` or `options` endpoints rather than hard-coded Flutter values.

Successful JSON responses use the conventions already established in `ppa-api-new`: collection data plus pagination metadata, a `data` member for a single resource, and a concise `message` for mutations. Validation failures use Laravel's standard HTTP 422 error format. Missing records use 404, unauthorized role/site access uses 403, unauthenticated requests use 401, and invalid state transitions use 422.

File fields are accepted as multipart uploads when the portal accepts files. Returned file/image values are mobile-consumable URLs. Existing database identifiers and route parameter types are preserved.

## Authorization and site isolation

Portal role and site behavior is authoritative. Non-cross-site roles may only read or mutate records for their own site. Cross-site access remains limited to roles already allowed by the portal. Mutation endpoints call a shared authorization policy or the existing `SiteContext` behavior. Approval endpoints preserve the portal's approver roles and state prerequisites.

No endpoint may rely only on Flutter hiding a menu. Server-side authorization is mandatory.

## Architecture

Generic registries remain appropriate for entities with equivalent behavior, such as the existing Inventory and Inspection registries. Asset types with materially different schemas, nested data, upload rules, or approval workflows use focused controllers that mirror the existing specialized API pattern.

New functionality will be grouped by domain:

- Route declarations in `routes/api.php`.
- Focused controllers under `app/Http/Controllers/Api`.
- Registry entries only when a type genuinely follows the generic contract.
- Shared support/services for repeated authorization, query, storage, or serialization behavior.
- Feature tests under `tests/Feature/Api` organized by domain.
- A final parity report under `docs` listing supported endpoints, missing source controllers, tests, and environmental blockers.

The unresolved `Api\\VhmsUnitController` reference is not implemented merely from its missing name. Existing portal VHMS controllers are evaluated method by method; endpoints are created only for flows backed by those controllers. The missing API class itself is included in the report.

## Data flow

1. Flutter authenticates and receives a Sanctum token.
2. Flutter loads `meta` or `options` for the selected form and site.
3. The API resolves the effective site and verifies role/site access.
4. Input is validated against the portal's business rules.
5. A database transaction applies the primary mutation and all portal-equivalent side effects.
6. Stored files are processed through the same storage conventions as the portal.
7. The API returns normalized JSON and mobile-consumable URLs.

Multi-record operations and flows with side effects use transactions so partial state is not committed.

## Error handling

- Unsupported registry type: 404 with a stable explanatory message.
- Invalid form data or invalid workflow state: 422.
- Authenticated user without role/site permission: 403, except record lookups intentionally hidden across sites, which remain 404.
- Missing record: 404.
- Unexpected server/database failure: logged by Laravel and returned through the existing exception pipeline without exposing secrets.

## Testing strategy

Every new behavior follows red-green-refactor:

1. Add a focused feature test and observe the expected failure.
2. Add the minimum production implementation.
3. Re-run the focused test and relevant domain tests.
4. Run route discovery, PHP syntax checks, and the full feasible suite.

Tests cover authentication, role/site boundaries, list/filter/pagination, detail, successful mutations, validation failures, approval/state transitions, uploads, and required side effects. Tests should use an isolated test database. If the existing schema is incompatible with SQLite, database-dependent MySQL tests will be run only when the configured test database is reachable and the environmental limitation will be reported explicitly.

## Completion criteria

- Every in-scope portal menu is mapped to an API endpoint or documented as unsupported because its source controller/flow is missing.
- Existing APIs are checked for parity; confirmed gaps are repaired.
- New endpoints have automated feature tests that were observed failing before implementation.
- `php artisan route:list --path=api` completes without reflection errors, except that a route backed only by a genuinely missing source controller must be removed or isolated and documented rather than left broken.
- PHP syntax validation succeeds for modified PHP files.
- The feasible automated test suite is run and its exact results are reported.
- A final report lists routes, files changed, implemented behavior, missing controllers, tests, and remaining environment blockers.
