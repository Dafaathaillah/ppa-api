# Maintenance Working Tree API Parity Design

## Objective

Bring `ppa-api-new` into API parity with backend behavior changed in the current `maintenance` working tree of `IT-PORTAL-HO-PALING-BARU-9-2026`, including staged, unstaged, and untracked source files, so those capabilities can be consumed safely by Flutter.

## Source Baseline

- Source repository: `IT-PORTAL-HO-PALING-BARU-9-2026`.
- Source branch: `maintenance`.
- Branch HEAD at audit time: `fdef0b9a` (`origin/maintenance`).
- Parity input: backend delta between `fdef0b9a` and the current dirty working tree, including untracked backend source.
- Target repository: `ppa-api-new`, branch `batch2-inv-api`.
- The source working tree is read-only for this project. No checkout, clean, reset, staging, or edits are permitted there.

## Parity Strategy

Parity is behavioral rather than a direct controller copy. Web controllers remain the source of business rules, validation, query semantics, status transitions, and side effects. Target endpoints retain REST JSON responses, Sanctum authentication, explicit site authorization, pagination suitable for Flutter, and relative storage paths.

Only changed maintenance behavior is in scope. Existing target behavior is retained when it is already equivalent. UI-only changes are used solely to infer request and response fields.

## Domains

### Inspection and PICA

Reconcile changed Laptop, Mobile Tower, Panel Box, Access Point, Switch, Wireless, Scanner, NVR, and CCTV flows. Cover process/update, approval and approval-all semantics, checklist data, inventory write-back, attached devices, imports where useful to mobile, and PICA synchronization. Reconcile `GenerateInspeksiService` and expose generation only through an administrator-authorized endpoint. Export endpoints are included only where Flutter needs a downloadable artifact.

### Inventory

Reconcile changed Laptop, Mobile Tower, Tower, NVR, and CCTV validation, generation, channel synchronization, import, CRUD, relationships, and response fields. Include the NVR/CCTV view-only model when its migration-backed behavior is consumed by the changed web flow.

### Operation

Reconcile Daily Job Monitoring filters, bulk approval, and export behavior; Daily Server Monitoring processing and approval; and Asset Delivery create, receive, reject, import preview/store, and detail behavior.

### Procurement and Requests

Reconcile Internal Procurement RKB changes, operational status, close/restore actions, and export. Reconcile Request Submission hardware/network/software/other/access workflows, draft lifecycle, approver actions, documents, and guest-facing behavior where a matching mobile API exists or is required.

### VHMS and Performance

Reconcile unit table filters and payload, evidence import/export/template, unit CRUD/search/detail, latest problem-unit rules, repair entry and history, completion, not-update confirmation, history synchronization, and KPI fields across sites. A new API controller may replace the currently dangling `Api\VhmsUnitController` reference only when backed by the changed maintenance controllers and services.

### Aduan Webhook

Port the untracked maintenance webhook side effect (`SendAduanWebhook`, observer registration, and configuration contract) only if it can run without changing existing push notification behavior. It is an event side effect, not a public mobile endpoint. Secrets remain environment configuration and are never copied from source files.

## API Contract Rules

- All non-public routes use `auth:sanctum`.
- Non-HO users can read and mutate only their own site.
- Cross-site roles follow the existing `SiteContext` policy.
- Mutation endpoints validate role as well as site.
- List endpoints paginate and provide stable JSON keys.
- File endpoints return downloads or documented URLs; stored database paths remain relative.
- Existing Flutter routes and response keys are backward compatible unless the source behavior makes them invalid; aliases are preferred over breaking removal.
- Status values are normalized where the target already established a canonical value, with legacy read aliases where needed.

## Controller Availability

Every changed source controller is mapped to a target controller or explicitly reported as not applicable. Missing source controllers are reported and skipped. Unlike the previous report-only exception, the dangling target `Api\VhmsUnitController` may now be implemented because matching maintenance source controllers exist and this request explicitly expands scope to the maintenance delta.

## Data and Migration Policy

No destructive migration is allowed. A maintenance migration is copied or adapted only when a changed API requires the table or column. Migration code must remain compatible with the target database; SQLite feature tests may use a focused schema when production migrations contain MySQL-only SQL.

## Testing

Each domain follows a red-green cycle:

- Route and controller integrity.
- Authentication, role, and cross-site access.
- Validation and status transitions.
- Database side effects and relationship synchronization.
- Import/export contract where implemented.
- Regression coverage for existing inspection, PICA, inventory, operation, VHMS, procurement, and Aduan APIs.

The focused parity suite must pass. The full suite is run and unrelated MySQL/environment failures are reported separately.

## Deliverables

- Updated API controllers, services, models, requests, routes, configuration examples, and narrowly required migrations.
- Feature tests for every changed target behavior.
- An updated parity report listing implemented, already-equivalent, skipped, and blocked items.
- Commits isolated to `ppa-api-new`; the IT-PORTAL maintenance working tree remains untouched.
