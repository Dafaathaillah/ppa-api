# Maintenance Working Tree API Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile every backend behavior changed in the dirty IT-PORTAL `maintenance` working tree with secure Flutter APIs in `ppa-api-new`.

**Architecture:** Build a machine-readable delta inventory from the maintenance working tree, map each changed controller/service/model to an existing or new target API component, and implement only missing behavioral differences. Preserve target REST conventions, Sanctum authentication, `SiteContext` authorization, and existing Flutter compatibility.

**Tech Stack:** PHP 8.2, Laravel, Sanctum, Eloquent, PHPUnit, SQLite focused test schemas, MySQL production schema.

**Spec:** `docs/superpowers/specs/2026-10-13-maintenance-working-tree-api-parity-design.md`

## Global Constraints

- Treat the dirty `maintenance` working tree as read-only source truth.
- Never copy environment secrets or modify source files.
- Preserve existing target routes and response keys.
- Add no destructive migration.
- Apply test-first changes and verify each domain independently.
- Keep the missing-controller report only for controllers with no maintenance source equivalent.

---

### Task 1: Delta inventory and route integrity

**Files:**
- Create: `docs/MAINTENANCE_API_PARITY_REPORT.md`
- Modify: `tests/Feature/Api/ApiRouteIntegrityTest.php`

**Interfaces:**
- Produces a domain/controller/behavior matrix with statuses `equivalent`, `updated`, `missing-source`, or `not-mobile-api`.

- [ ] Capture tracked and untracked maintenance backend files and public methods.
- [ ] Map every changed source behavior to target routes/controllers.
- [ ] Write a failing route-integrity assertion for each missing target action.
- [ ] Run `php artisan test tests/Feature/Api/ApiRouteIntegrityTest.php --compact` and confirm expected failures.

### Task 2: Inspection, PICA, and inventory delta

**Files:**
- Modify relevant files under `app/Http/Controllers/Api/*Inspection*`, `app/Http/Controllers/Api/Inventory*`, `app/Services`, `app/Models`, and `routes/api.php`.
- Add focused tests under `tests/Feature/Api`.

**Interfaces:**
- Reconciles changed inspection generation, process, approval-all, import/export, PICA device labels, NVR channel synchronization, CCTV/NVR/Tower inventory fields, and attached-device behavior.

- [ ] Write failing tests for confirmed maintenance deltas not already represented.
- [ ] Implement minimal controllers/services/routes while preserving current contracts.
- [ ] Run all inspection, PICA, and inventory API tests.

### Task 3: Operation, procurement, and request delta

**Files:**
- Modify existing operation, delivery, procurement, request API controllers and routes.
- Add focused feature tests.

**Interfaces:**
- Reconciles monitoring filters/approval/export, asset delivery lifecycle/import, RKB and operational status, close/restore, drafts, workflow approval, documents, and guest operations.

- [ ] Write failing tests for each confirmed missing action.
- [ ] Implement the smallest parity changes.
- [ ] Run operation and request tests.

### Task 4: VHMS and performance delta

**Files:**
- Create or modify `app/Http/Controllers/Api/VhmsUnitController.php` when required.
- Modify VHMS controllers, queries, services, requests, KPI API, routes, and tests.

**Interfaces:**
- Provides unit options/CRUD/search/detail plus changed unit table, payload, evidence, problem, repair, completion, not-update, history sync, and KPI behavior.

- [ ] Write failing route and behavior tests for the dangling VHMS controller and changed maintenance actions.
- [ ] Implement source-backed behavior with role/site authorization.
- [ ] Run VHMS and KPI tests.

### Task 5: Aduan webhook parity

**Files:**
- Create or modify webhook job/observer/service/config only where target behavior is missing.
- Modify `.env.example` without secrets.
- Add webhook feature tests.

**Interfaces:**
- Dispatches maintenance-compatible webhooks on Aduan lifecycle changes without disrupting existing push events.

- [ ] Write failing lifecycle and disabled-configuration tests.
- [ ] Implement queued webhook delivery and observer registration.
- [ ] Run Aduan webhook and existing Aduan tests.

### Task 6: Verification and report

**Files:**
- Complete `docs/MAINTENANCE_API_PARITY_REPORT.md`.

**Interfaces:**
- Produces the final endpoint/change/controller/test report.

- [ ] Run `php -l` on changed PHP files.
- [ ] Run focused domain tests and require zero failures.
- [ ] Run the full suite and record unrelated environment failures.
- [ ] Run `git diff --check`, inspect status, and commit target changes only.
