# Unified Inspection and PICA Flow Design

## Objective

Make every inspection device exposed to Flutter follow the same secure lifecycle as the established specialized inspection controllers: site-scoped reads, role-scoped processing and approval, and bidirectional synchronization between an inspection and its PICA record.

## Scope

The design covers Laptop, Computer, Printer, Mobile Tower, Panel Box, Access Point, Switch, Wireless, Scanner, Server, Tower, NVR, and CCTV. It also covers the inspection PICA endpoints used to list, view, and update findings.

The missing `App\Http\Controllers\Api\VhmsUnitController` is explicitly outside this change, as are export/import endpoints and database schema changes.

## API Compatibility

Existing routes and response keys remain available. Specialized routes continue to use:

- `GET /api/inspections/{device}` for a site-scoped list.
- `GET /api/inspections/{device}/{inspection}` for a site-scoped detail.
- `POST /api/inspections/{device}/{inspection}/process` to submit an inspection.
- `POST /api/inspections/{device}/{inspection}/approval` to accept or reject it.

Laptop, Computer, and Printer gain the specialized process and approval behavior while their current generic `GET`, `PUT`, and `PATCH` routes remain usable for backward compatibility. Specialized routes must be declared before generic `{type}` routes.

## Authorization

- Every list and detail query is constrained to the authenticated user's site unless `SiteContext::canAccessAnySite()` permits cross-site access.
- A technician or group leader may process only records from their own site.
- Only a group leader may approve a completed inspection from their own site.
- Unauthorized detail access returns `404` to avoid exposing record existence; unauthorized mutations return `403`.
- Site query parameters cannot widen access beyond the authenticated user's allowed site.

## Inspection Lifecycle

Processing validates each device's existing checklist fields and image inputs. It updates the inspection status, timestamp, inspector, inventory condition/status, checklist values, remarks, and evidence images in one database transaction.

Approval values are normalized to `approved` for acceptance and `reject` for rejection. Existing consumers that display legacy values may treat `approve` as an alias during the transition, but no new write stores `approve`.

## PICA Lifecycle

Each inspection has at most one canonical `pica_inspeksis` row keyed by `inspeksi_id`, except CCTV where detailed findings remain the source records and their linked PICA is synchronized by `pica_number`.

The lifecycle is:

1. A submitted open finding creates a PICA when none exists or updates the existing PICA.
2. Reprocessing a finding updates the same PICA and never creates a duplicate.
3. Closing a finding preserves its history and writes the close status, action, action photo, remarks, due date, and closing user.
4. Removing all findings deletes the linked PICA and clears the inspection's PICA number and finding fields.
5. Updating through the PICA API writes the PICA and corresponding inspection/finding record inside one transaction.

PICA supports all device types in scope, including Tower. Device-type resolution must be explicit to prevent a coincidentally matching UUID from updating a different inspection table.

Image fields store disk-relative paths consistently. API resources may expose a derived public URL, but database values must not mix absolute URLs and relative paths.

## Components

- A shared site-scoped inspection query helper applies read authorization consistently.
- A shared PICA synchronization service owns create, update, close, and delete behavior for ordinary inspection models.
- Existing specialized controllers retain device-specific validation and inventory mappings.
- The generic inspection controller remains as a compatibility adapter for Laptop, Computer, and Printer.
- The PICA controller delegates device resolution and persistence rather than probing tables in an ambiguous order.

## Error Handling

- Invalid payloads return `422` with field errors.
- Missing or inaccessible records return `404`.
- Invalid role or cross-site mutation returns `403`.
- Inspection and PICA mutations are transactional; image files written before a failed transaction are cleaned up where the existing storage helper supports cleanup.

## Verification

Feature tests will prove:

- List and detail endpoints cannot expose another site's records.
- Every device can create, update, close, and remove its PICA through inspection processing.
- Every PICA device type updates its corresponding inspection record.
- Approval permissions and normalized values are consistent.
- Existing NVR and CCTV flows remain passing.
- Generic Laptop, Computer, and Printer routes remain backward compatible.

The focused inspection suite must pass. The full suite will also be run, with unrelated pre-existing failures reported separately rather than hidden.
