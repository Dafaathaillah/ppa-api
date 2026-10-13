# Maintenance Working Tree API Parity Report

Audit source: dirty working tree `IT-PORTAL-HO-PALING-BARU-9-2026` on branch `maintenance`, based on HEAD `fdef0b9a`. The source tree was inspected read-only.

## Implemented in this parity pass

| Maintenance change | Target result |
|---|---|
| `UnitVhmsController` CRUD changes | Added source-backed `Api\VhmsUnitController` for options, create, detail, update, and developer-only delete. |
| VHMS repair creation when a `not_update` unit is edited | API update creates repair history transactionally with operational shift/date and root-cause fallback. |
| `OperationalShift` support | Added target support class with the same 06:00–18:00 operational shift boundary. |
| `GenerateInspeksiService` and controller | Added authenticated `POST /api/inspections/generate` covering AP, CCTV, computer, laptop, mobile tower, NVR, panel box, printer, scanner, switch, tower, and wireless. CCTV generation also ensures NVR generation. |
| Aduan WhatsApp webhook | Added queued signed per-site webhook with retry/backoff and idempotency key. Existing Aduan push events remain active. |
| Aduan webhook configuration | Added environment-only secret/site URLs; no secret values were copied. |
| Dangling API route | Removed the documented missing-controller exception; all registered API controller classes/actions now resolve. |

## Already equivalent in `ppa-api-new`

| Domain | Maintenance delta coverage already present in target |
|---|---|
| Inspection | Site-scoped list/detail; process; approval; NVR/CCTV; Panel Box attached devices; AP/Switch/Wireless/Scanner; Mobile Tower; Tower; inventory write-back; evidence; PICA lifecycle. |
| PICA | Device filter/meta, detail/update, NVR/CCTV display names and relations, open/closed filtering, explicit type resolution, and synchronization back to inspection records. |
| Inventory | NVR, Tower, CCTV through generic/specialized APIs; CRUD and site authorization; NVR generation and channel data support in the specialized inventory flow. |
| Daily monitoring | Daily Server Monitoring CRUD/process/approval and Operations monitoring list, timezone-aware date/shift filtering, approval, and export. |
| Asset delivery | Options, laptop/user lookup, code generation, create, receive, reject, detail, delete, import preview/store, and template download. |
| Procurement/request | Existing target submission and operational APIs cover the mobile workflows; web-only Inertia rendering and browser document previews are not duplicated as JSON endpoints. |
| VHMS | Unit table/payload, evidence import/export/template, problems, repair history, completion, not-update confirmation, and KPI endpoints were already registered. |
| Performance | KPI VHMS index/filter/feedback/store/breakdown/summary and inspection/response-time KPI APIs already exist. |

## Not exposed as mobile mutations

- PDF-only inspection exports remain download/report concerns rather than duplicated record mutations.
- Web page render methods (`index`, `create`, `edit`, Inertia responses) are represented by JSON list/meta/detail endpoints rather than copied directly.
- Console import commands remain server maintenance commands.
- The NVR/CCTV view-only pivot is included because maintenance channel synchronization counts that relation for `VIEW_ONLY` NVR records.

## API additions

- `GET /api/vhms/{site}/options`
- `POST /api/vhms/{site}/units`
- `GET /api/vhms/{site}/units/{unit}`
- `PUT|PATCH /api/vhms/{site}/units/{unit}`
- `DELETE /api/vhms/{site}/units/{unit}`
- `POST /api/inspections/generate`
- `POST /api/inventory/nvrs/sync-channels`

## Security rules

- Routes remain protected by Sanctum.
- VHMS units are site-scoped; only the maintenance site-selector account or an ICT developer can cross sites.
- VHMS deletion is developer-only.
- Inspection generation is limited to developer, HO, or group-leader roles and obeys site authorization.
- Webhook payloads are HMAC-SHA256 signed and contain an idempotency key.

## Verification

- Route integrity confirms no missing API controller classes or methods.
- Focused inspection, PICA, VHMS-route, and webhook tests are included in the parity verification command.
- The legacy full suite still depends on the unavailable local MySQL database; those environment failures are reported separately.
