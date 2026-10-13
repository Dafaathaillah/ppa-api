# IT-PORTAL-HO to Mobile API Parity Report

**Audit date:** 2026-10-13

**Portal source:** `IT-PORTAL-HO-PALING-BARU-9-2026`

**API target:** `ppa-api-new`

## Executive summary

The target already contained broad API coverage for Inventory, Operation, VHMS, Performance, and PICA, but several routes were unusable or incomplete. This change adds the missing NVR/CCTV inspection APIs, expands PICA to the device types supported by the portal, restores source-backed dependencies that existing asset APIs require, and fixes route precedence that made nine specialized Inventory APIs and the new specialized Inspection APIs unreachable.

The Flutter project is not present in this workspace, so verification covers portal-to-API parity and mobile-oriented JSON contracts, not Flutter service/model compilation.

## Controller gaps intentionally not implemented

| Project | Missing controller | Impact |
|---|---|---|
| `ppa-api-new` | `App\Http\Controllers\Api\VhmsUnitController` | `/api/vhms/{site}/options` and VHMS unit create/detail/update/delete reference a class that does not exist. Per instruction, this is report-only. Existing VHMS list/payload/evidence/problem/history routes point to available portal controllers. |
| IT-PORTAL-HO | `LaptopLoanBibController` | Imported by `routes/web.php`, but the controller file is absent. No API was invented. |
| IT-PORTAL-HO | `ComputerLoanBibController` | Imported by `routes/web.php`, but the controller file is absent. No API was invented. |

## Implemented changes

### NVR inspection

Added:

- `GET /api/inspections/nvrs`
- `GET /api/inspections/nvrs/{inspection}`
- `POST /api/inspections/nvrs/{inspection}/process`
- `POST /api/inspections/nvrs/{inspection}/approval`

The API provides site/month/year/status/location filters, pagination, inventory and findings relationships, technician/group-leader site authorization, checklist validation, inventory status write-back, finding/PICA creation, optional image uploads, and approval state handling.

### CCTV inspection

Added:

- `GET /api/inspections/cctv`
- `GET /api/inspections/cctv/{inspection}`
- `POST /api/inspections/cctv/{inspection}/process`
- `POST /api/inspections/cctv/{inspection}/approval`

The API provides the equivalent site-scoped list/detail, CCTV checklist processing, inventory status/location write-back, finding/PICA persistence, file upload support, and group-leader approval.

### PICA

`GET /api/pica-inspeksi` now accepts all device types exposed by the portal controller:

- Laptop
- Computer
- Mobile Tower
- Printer
- Panel Box
- Access Point
- Switch
- Wirelless (portal spelling retained for compatibility)
- Scanner
- Server
- NVR
- CCTV

Default queries hide `CLOSE`/`CLOSED` findings. Explicit `status_pica` filtering, date filtering, NVR PICA relations, and CCTV nested-finding filtering are supported. `GET /api/pica-inspeksi/meta` now returns `device_types` for Flutter dropdowns. Detail and update requests may pass `device_type=NVR` or `device_type=CCTV` to avoid ambiguous cross-table identifiers; CCTV updates also require `finding_id`. The previous detail fallback behavior remains available.

The portal provides list support for Access Point, Switch, Wirelless, Scanner, and Server, but its detail/update resolver does not implement those types. Their API support is therefore list/filter only rather than invented behavior.

### Route precedence

Moved generic Inventory and Inspection fallback routes after all specialized routes. Before this fix, requests such as `/api/inventory/tabs`, `/api/inventory/nvrs`, `/api/inventory/servers`, and `/api/inventory/panel-boxes` were captured by `InventoryApiController` as unsupported registry types and returned 404.

The following specialized Inventory APIs are now reachable:

- tabs / Tab OVA
- NVR
- server
- Starlink
- panel box
- tower
- access point
- switch
- wireless

Stock routes were already declared before the generic fallback and remain unchanged.

### Restored source-backed dependencies

Existing Asset Delivery and Asset Mutation controllers referenced classes missing from the target. Their portal implementations were restored:

- `DeliverAssetTemplateExport`
- `DeliverAssetImport`
- `DeliverAssetPreviewImport`
- `MutationAssetPdfStorage`
- `TowerInspectionPhotoStorage`

## Domain parity status

| Domain | Status | Notes |
|---|---|---|
| Inventory | API present; route precedence fixed | Generic device CRUD plus specialized Tab, NVR, server, Starlink, panel box, tower, stock, AP, switch, and wireless APIs. |
| Inspeksi | API coverage completed for menu types with source controllers | NVR and CCTV added; existing laptop/computer/printer/mobile tower/AP/switch/wireless/scanner/server/panel box/tower APIs retained. |
| Operation | Existing API retained | Jobs, monitoring, unscheduled jobs, asset transfer/delivery/mutation, daily server monitoring, and password bank routes exist. Missing asset helper classes restored. |
| VHMS | Partial; known missing controller | List, payload, evidence, problems, repairs, completion, and history routes exist. CRUD/options controller remains report-only. |
| Performance | Existing API retained | KPI response time, KPI inspection, KPI VHMS, complaint analysis, dashboard, and inspection chart routes exist. |
| PICA | Expanded | All portal list types accepted; NVR/CCTV list and detail normalization added; meta options added. |

## Tests added

- `ApiRouteIntegrityTest`: specialized route precedence, source-backed dependency autoloading, and exact missing-controller allowlist.
- `NvrInspectionApiTest`: site-scoped list, process, inventory write-back, and cross-site hiding.
- `CctvInspectionApiTest`: list, process, inventory write-back, and group-leader approval.
- `PicaVideoInspectionApiTest`: Flutter meta options, NVR open/closed filtering and detail, CCTV finding-status filtering and detail.

The focused tests use an isolated SQLite schema and do not depend on the unavailable local MySQL service.

## Environment limitations

- The target project's legacy/full feature suite expects MySQL database `new_itportalv4`; the configured server currently refuses the connection.
- A full-suite fallback run against SQLite completed with 11 passing and 27 failing tests. The 26 database-backed legacy failures stop on a pre-existing MySQL-only migration statement (`ALTER TABLE ... MODIFY COLUMN ... ENUM`), which SQLite cannot execute. The remaining existing `ExampleTest` expects `/` to return 200 although the application redirects it with 302.
- The portal runtime cannot execute on the current PHP 8.2.12 binary because its installed Composer dependencies require PHP 8.3 or newer. Portal parity was therefore established by static route/controller/model inspection.
- `php artisan route:list --path=api` in the target still reports the known missing `Api\VhmsUnitController`. The new route-integrity test independently validates every other API controller action and keeps the exact missing class explicit.
