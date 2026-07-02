# API Contract — Selective System Reset (9-category data wipe)

> **Status:** IMPLEMENTED (core + all domain seams wired, green) / spec-author = `backend-architect`.
> The core orchestrator (`app/Support/System/SystemResetService`), HTTP layer, and every owning-domain
> `purgeAll()` seam are landed. This doc reflects the **as-built** implementation; §9 product decisions
> (P1–P7) are all resolved as noted inline. Sequenced BEFORE `frontend-specialist` builds the
> Settings → Система → «Сброс системы» tab (`design-handoff/redesign/system-section.jsx` → `ResetTab`).
>
> **As-built seam map (category → class::method):**
> `deals` → `Sales\DealService::purgeAll` · `contacts` → `Crm\ContactService::purgeAll` ·
> `companies` → `Crm\CompanyService::purgeAll` · `tasks` → `Activity\ActivityService::purgeAll` ·
> `docs` → `Contracts\DocumentService::purgeAll` (Contracts documents only — see §1 row 5 note) ·
> `finance` → none (greenfield no-op, count 0) · `automations` → `Automation\AutomationService::purgeAll`
> (clears `automation_runs` + `pipeline_automations`) · `directories` → COMPOSITION of six seams
> (`Crm\CrmDirectoryPurgeService` + `Crm\TagService` + `Crm\CustomFieldService` + `Catalog\ProductService`
> + `Sales\LostReasonService` + `Contracts\MessageTemplateService`, all `::purgeAll`) ·
> `logs` → `App\Support\System\LogPurger::purgeAll` (entity_logs; backend-architect-owned).
> The orchestrator (`App\Support\System`) only sequences these — it never touches a foreign table.
>
> **Nature: DESTRUCTIVE — permanent, irreversible per-row deletion.** Every design decision below
> favours safety over ergonomics. Grounded in `docs/backend-standard.md` (§1 layering, §3 boundaries,
> §4 authz, §5 invariants) + `ARCHITECTURE.md`. Business intent from
> `design-handoff/redesign/Settings-redesign-visual.md` §10.4.

---

## 0. Context: this REPLACES the existing full-wipe endpoint

**There is already a `POST /api/system/reset` — it is a full `migrate:fresh` wipe**, NOT selective:

- `src/app/Http/Controllers/System/SystemResetController.php` → calls `Artisan::call('app:reset-clean')`
  (`src/app/Console/Commands/System/ResetCleanCommand.php`) → `migrate:fresh` + re-seed baseline.
- Guarded by `config('system.reset_enabled')` (off by default), `system-reset` gate (admin-only),
  and a fixed confirmation phrase `СБРОСИТЬ НАСТРОЙКИ` (`SystemResetRequest`).
- Returns `requires_relogin: true` because the wipe drops the sessions/token tables including the
  caller's own token.

The redesign explicitly calls for **selective** deletion — "*unlike the product's full-wipe behaviour,
per request*" (design comment, `system-section.jsx:3`). So:

> **PRODUCT DECISION NEEDED (P1).** Two options — pick one:
> - **(A, recommended) Repurpose the existing route** `POST /api/system/reset` into the selective
>   form below, and **retire** `app:reset-clean` full-wipe from the HTTP surface (keep the artisan
>   command for CLI/dev only). The old full-wipe is not reachable from the new UI, and a
>   "delete everything" path is more dangerous than the selective one it's being replaced by.
> - **(B) Keep both:** old full-wipe stays at a separate route; selective lives at a new
>   `POST /api/system/reset/selective`. Adds a second destructive surface to guard — not preferred.
>
> This contract is written for **(A)**: the selective endpoint takes over `POST /api/system/reset`.
> The confirmation phrase changes from `СБРОСИТЬ НАСТРОЙКИ` to `СБРОСИТЬ` (matches
> `system-section.jsx:120` `S_CONFIRM_WORD='СБРОСИТЬ'`). If product picks (B), swap the route and
> keep the old phrase on the legacy route.

---

## 1. The 9 categories → owning domains → tables

The design's 9 checkboxes (`S_CATS`, `system-section.jsx:109-119`) mapped to real tables (verified
against `src/database/migrations/`) and owning domains. Delete order within a category respects FK
(children before parents); `cascadeOnDelete` FKs let the parent delete fan out, `RESTRICT`/pivot rows
are deleted explicitly first.

| # | key | Label (RU) | Owning domain(s) | Tables (delete order: children → parents) | FK notes |
|---|---|---|---|---|---|
| 1 | `deals` | Сделки | **Sales** (+ Log, Activity co-owned rows) | `deal_stage_history`, `deal_audits`, `deal_contacts`(pivot), `deal_products`, `deals` | children `cascadeOnDelete` on `deals`; deleting `deals` fans out. `entity_logs` rows with `subject_type='deal'` handled by category **7 (logs)**, NOT here (see §2 cross-category rule). |
| 2 | `contacts` | Контакты | **Crm** | `crm_contact_relations`, `crm_contact_company_links`(pivot), `contact_channels`, `crm_contacts` | `deal_contacts` already gone via cat 1 or cascades. Do NOT delete `crm_contact_positions` (dictionary → cat 9). |
| 3 | `companies` | Компании | **Crm** (+ Contracts requisites) | `company_channels`, `company_client_status_log`, `company_requisites`, `crm_companies` | ⚠️ **FK RESTRICT risk:** `deals.company_id`, `crm_contact_company_links.company_id`. Companies can only be wiped if deals (cat 1) + contact-links (cat 2) are also selected, OR the delete is blocked. See §3 dependency guard. |
| 4 | `tasks` | Задачи и активности | **Activity** | `activities` | polymorphic target; deleting rows is self-contained. Activity-derived `entity_logs` → cat 7. |
| 5 | `docs` | Документы и файлы | **Contracts** | `approvals`, `document_remarks`, `document_items`, `document_attachments`, `document_revisions`, `documents` (via `Contracts\DocumentService::purgeAll`) | **AS-BUILT: Contracts documents only.** `certificates` **EXCLUDED** — they are **Onboarding** data (course-completion certs), and Onboarding is not one of the 9 categories (decision, see §9 P8). `crm_folders_and_files` **NOT wiped today** — Crm files carry physical-storage side-effects and Crm has no `purgeAll` seam for them; documented gap (§9 P9), safe under the allow-list (a table with no cleaner is never touched). Number-sequence counters (`contract_number_sequences`/`certificate_number_sequences`/`document_number`) **preserved** (P3 — config-like, numbering continuity). |
| 6 | `finance` | Финансовые операции | **⚠️ GREENFIELD — `Domain/Finance` does NOT exist yet** | *none today* — no `fin_*`/payments/invoices tables in schema | Payment state today lives on `deals` (payment fields) + `entity_logs` action `payment_fixed`. See §5 gap. |
| 7 | `logs` | Журналы и история | **Log** (backend-architect-owned) | `entity_logs` only (via `App\Support\System\LogPurger`) | **AS-BUILT: `entity_logs` only.** `automation_runs` is cleared by cat 8 (`AutomationService::purgeAll`), `deal_audits`/`deal_stage_history` by cat 1 (`DealService::purgeAll`) — each audit table is owned by ITS category, so `logs` never double-handles them (the "if not gone via cat 1/8" hedges always resolve to "gone"). ⚠️ This category deletes the **entity audit trail itself**; the reset's own audit row is written AFTER deletion completes and OUTSIDE the transaction (§4). |
| 8 | `automations` | Правила автоматизаций | **Automation** | `automation_runs` (child), `pipeline_automations` (via `Automation\AutomationService::purgeAll`) | `pipeline_automations` = the configured rules; the purger clears `automation_runs` first (FK-safe). `forms` (webforms) **EXCLUDED** — integration config, not automation rules (P4). |
| 9 | `directories` | Справочники | **Crm / Catalog / Sales / Contracts** | COMPOSITION of 6 seams: `crm_sources`,`acquisition_channels`(+`acquisition_channel_history`),`disconnect_reasons` (`Crm\CrmDirectoryPurgeService`) · `crm_tags` (`Crm\TagService`) · `custom_field_defs` (`Crm\CustomFieldService`) · `catalog_products`+`catalog_product_{groups,plans,prices}` (`Catalog\ProductService`) · `lost_reasons` (`Sales\LostReasonService`) · `message_templates`+`message_template_bindings` (`Contracts\MessageTemplateService`) | ⚠️ **Heaviest FK web** — but the §3 prerequisite matrix forces `deals`+`contacts`+`companies` to be co-selected (already gone) before this runs, and all dictionary FKs are `nullOnDelete`, so no RESTRICT. **AS-BUILT: no single parent → composed count = SUM of live rows across all these tables** (§6.1). **`catalog_exchange_rates` (курсы валют) EXCLUDED** despite the design copy — FX history is treated as config; losing it breaks historical re-computation (P5, resolved = exclude). |

**Design count labels** (`1 248 записей` etc.) are mock strings — the live preview endpoint (§6) returns
real counts.

---

## 2. What is NEVER deletable (hard black-list — enforced server-side, not just UI)

**None of the 9 categories may ever touch these**, regardless of selection. The reset service NEVER
references them:

| Never-delete | Tables | Why |
|---|---|---|
| Users & auth | `users`, `personal_access_tokens`, `telegram_link_tokens`, `sessions`/`cache`/`jobs` | Design: "Настройки аккаунта и учётные записи сохраняются" (`system-section.jsx:137`). Deleting users/tokens would log everyone out incl. the admin. |
| Roles / permissions | `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions` (spatie) | Authz store (backend-standard §4). Never wiped — reset is not a re-provision. |
| Visibility config | `visibility_settings` | Admin-edited authz matrix. |
| Org structure | `departments` | Users reference `department_id`; org tree is config, not business data. Design category list has NO "departments" checkbox — intentional. |
| Pipelines & stages | `pipelines`, `pipeline_stages` | Structural config (deals reference `stage_id`). Deals (cat 1) are business data; the funnel they live in is config. NOT in the 9 categories. |
| Licensor / bank config | `licensor_entities`, `licensor_bank_accounts` | Company's own legal entities (config). |
| Contract templates | `templates`, `template_versions`, `template_variables`, `approval_routes` | Document generation config. `documents` (cat 5) are instances; templates are config. |
| Meeting-report registry | `meeting_report_questions` | Config. |
| Migration bookkeeping | `migration_maps`, `amo_product_mappings`, `external_refs`, `amo_import` user | ETL parity state — never user-facing data. |

**Rule (encode as an allow-list, not a deny-list):** the reset service holds an explicit map
`category → [table cleaners]`. A table not in the map cannot be deleted. This is safer than "delete
everything except X" — a newly added table defaults to *protected*, not *wiped*.

> **This allow-list is the single most important safety invariant.** New domains (Finance, CS) that
> add tables are **not** silently swept into `directories`/`logs` — they only become resettable when
> someone explicitly adds a cleaner + test.

---

## 3. FK ordering & cross-category dependency guard

The categories are not independent — some deletions are only valid if a prerequisite category is also
selected. Two mechanisms:

**(a) Intra-category order** — each category cleaner deletes children before parents (table lists in
§1 are already in delete order). Where a parent has `cascadeOnDelete` children, the cleaner MAY rely on
the cascade, but the contract lists explicit order so the behaviour is deterministic on both pgsql and
the sqlite test suite (sqlite FK enforcement differs).

**(b) Inter-category prerequisite matrix** — a selected category whose parent rows are referenced by an
**unselected** category's rows would hit an FK RESTRICT. The service validates the selection set BEFORE
deleting anything and returns `422` with the blocking dependency. Known edges:

| If selected | Requires also selected (or 422) | Reason |
|---|---|---|
| `companies` | `deals` **and** `contacts` | `deals.company_id`, `crm_contact_company_links.company_id` reference companies. |
| `contacts` | (none — `deal_contacts` cascades from deals or is a pivot) | but if `deals` NOT selected, contact→deal pivot rows are cleared as part of contact cleanup (pivot delete, deals survive). |
| `directories` | `deals`, `contacts`, `companies` (the ones whose FKs point at dictionaries) | `deals.lost_reason_id`, `deals.source_id`, `deal_products.product_id`, tag pivots. Wiping dictionaries under live business data = FK error. |

> **PRODUCT DECISION (P2): hard-block vs. cascade-clean.** Two ways to handle prerequisites:
> - **(recommended) Hard-block (422):** if you pick `companies` without `deals`, the API rejects the
>   whole request with a clear message ("Нельзя удалить Компании, не удалив Сделки"). Predictable,
>   no surprise data loss. The UI should gray-out / auto-select prerequisites.
> - **Auto-cascade:** picking `companies` silently also deletes dependent deals. Rejected — hides
>   scope of deletion from the operator; violates "show exactly what will be deleted".
>
> This contract specifies **hard-block (422)**. Frontend `system-section.jsx` currently allows any
> combination — it will need prerequisite-aware checkbox logic (FE work item, see §7).

**(c) Category delete order (when multiple selected)** — the service orders selected categories so
FK-dependent categories run first: `deals → contacts → companies → tasks → docs → finance →
automations → directories → logs`. **`logs` is deliberately LAST** so per-category deletion counts
(and any failures) can still be recorded to `entity_logs` before that table is itself cleared, and the
final audit row (§4) is written after the log table is wiped.

---

## 4. Mandatory audit — written to `Domain/Log` (`entity_logs`)

**Every reset writes ONE audit row**, even (especially) when `logs` category is selected. Uses the
existing `EntityLogService::record()` (`src/app/Domain/Log/Services/EntityLogService.php:49`) — the
single cross-domain entry point:

- `subjectType = LogSubjectType::System` (already exists, `LogSubjectType.php:32`).
- `subjectId = 0` (system-scoped, no entity).
- `actor = $request->user()` (the admin who triggered it — never null; endpoint is admin-only).
- `action` — **NEW enum case required:** `LogAction::SystemReset = 'system_reset'` (add to
  `src/app/Domain/Log/Enums/LogAction.php`, no migration — backed string column). Mirrors the existing
  `PermissionChanged`/`VisibilityChanged` System-subject cases.
- `meta` (free-form JSON):
  ```json
  {
    "categories": ["deals", "contacts", "companies"],
    "deleted": { "deals": 1248, "contacts": 3972, "companies": 864 },
    "total_deleted": 6084,
    "confirmation_ok": true,
    "ip": "10.0.0.5"
  }
  ```

**Ordering constraint:** the audit row is `record()`-ed **AFTER** all deletions commit. If `logs` was
among the selected categories, the `entity_logs` table was truncated mid-run — the audit row is a fresh
insert after the wipe, so the reset event is the first row in the freshly-emptied log. This is intended:
the reset's own trace always survives. (Do NOT wrap the audit insert in the same transaction as the
`logs`-category delete, or it would be rolled into the truncate.)

> Because `logs` self-deletion + post-hoc audit-insert is a genuine ordering hazard, this is the one
> place a reviewer must check the implementation matches the contract exactly.

---

## 5. Transactionality

**Per-category transaction, not one giant transaction.** Each selected category's deletion runs inside
its own `DB::transaction()`. Rationale:

- A single mega-transaction over 8 categories on pgsql holds long locks and, on failure, rolls back
  partial progress the operator already saw counted — but the operator has no way to know *which*
  category failed.
- Per-category: if `directories` fails an FK check, `deals`/`contacts`/`companies` already committed;
  the response reports `deleted` per category + `failed: ['directories']` with the error. The operator
  sees exactly how far it got.
- The §3 prerequisite validation runs **before any transaction opens**, so the common FK-violation case
  is a clean 422 with nothing deleted, not a mid-run partial.

> **PRODUCT DECISION (P6): all-or-nothing vs. best-effort per-category.** Contract specifies
> **best-effort per-category with a per-category report** (above). If product wants strict
> all-or-nothing, wrap all categories in one transaction and return 500 on any failure with zero
> deletions — simpler to reason about but worse operator feedback. Recommend per-category.

---

## 6. Endpoints

### 6.1 Preview — `GET /api/system/reset/preview`

Returns live row counts per category so the UI shows real numbers instead of the mock `S_CATS[].count`.

- **Auth:** `auth:sanctum` + `can:system-reset` (admin only — even *counting* rows is admin-gated, and
  it reveals total data volume).
- **Guard:** same `config('system.reset_enabled')` check as the write path — if reset is disabled for
  the environment, preview 403s too (don't advertise a disabled feature).
- **Response:**
  ```json
  {
    "data": {
      "enabled": true,
      "confirmation_phrase": "СБРОСИТЬ",
      "categories": [
        { "key": "deals", "label": "Сделки", "count": 1248, "requires": [] },
        { "key": "companies", "label": "Компании", "count": 864, "requires": ["deals", "contacts"] },
        { "key": "finance", "label": "Финансовые операции", "count": 0, "requires": [] }
      ]
    }
  }
  ```
  `count` = live `COUNT(*)` of the category's **parent** table (deals count for `deals`, documents for
  `docs`, etc.) — one representative number, matching the design's single count-per-row. `requires` =
  the §3 prerequisite keys, so the FE can drive checkbox logic from the API (single source of truth,
  no FE-hardcoded dependency map).

### 6.2 Execute — `POST /api/system/reset`

- **Auth:** `auth:sanctum` + `can:system-reset` (admin only). **Explicitly NOT director** — `system-reset`
  is not in director's permission set; only `admin` holds it. Enforced in `SystemResetRequest::authorize()`
  via `$user->can('system-reset')` (backend-standard §4 — never inline role check).
- **Rate limit:** `throttle:3,1` per user (3/min) on the route — a destructive op should never be
  hammered; combined with the phrase gate this makes accidental/scripted mass-invocation impossible.
- **Request body:**
  ```json
  {
    "categories": ["deals", "contacts", "companies"],
    "confirmation": "СБРОСИТЬ"
  }
  ```
- **Validation (`SystemResetRequest`, FormRequest only — no inline validate):**
  - `categories`: `required, array, min:1`.
  - `categories.*`: `required, string, Rule::in(<9 category keys>)` — unknown keys rejected 422.
  - `confirmation`: `required, string, Rule::in(['СБРОСИТЬ'])` — server-side backstop for the
    "type the phrase" UX (mirrors existing `SystemResetRequest` pattern).
  - `authorize()`: `$this->user()?->can('system-reset') ?? false`.
- **Controller (thin):** guard `config('system.reset_enabled')` → run prerequisite check → call ONE
  service method `SystemResetService::reset(array $categories, User $actor): ResetResultData` → return
  `SystemResetResource`.
- **Success response (200):**
  ```json
  {
    "data": {
      "reset": true,
      "deleted": { "deals": 1248, "contacts": 3972, "companies": 864 },
      "total_deleted": 6084,
      "failed": [],
      "requires_relogin": false
    }
  }
  ```
  `requires_relogin` is **false** for selective reset — users/tokens are never touched (§2), so the
  admin's session survives (unlike the legacy full-wipe). The UI shows the success toast and refreshes
  the preview counts.
- **Error responses:**
  - `403` — `system.reset_enabled` off, or caller lacks `system-reset`.
  - `422` — bad phrase, empty/invalid categories, or **unmet prerequisite** (§3): message names the
    missing category. `errors.categories` carries the detail.
  - `429` — rate limit.
  - `200` with non-empty `failed[]` — partial success (per-category best-effort, §5); the resource
    still returns per-category `deleted` for the categories that succeeded.

---

## 7. Gap analysis — what exists vs. what to build

| Piece | Status | Action | Size |
|---|---|---|---|
| `POST /api/system/reset` route | **EXISTS** (full-wipe) | Repurpose to selective (P1-A) or add sibling route (P1-B) | — |
| `SystemResetController` | EXISTS (calls artisan wipe) | Rewrite: thin, call `SystemResetService` | S |
| `SystemResetRequest` | EXISTS (phrase only) | Extend: add `categories` rules, change phrase to `СБРОСИТЬ` | S |
| `system-reset` gate + admin-only | **EXISTS** (spatie permission, `AppServiceProvider.php`) | Reuse as-is | — |
| `config('system.reset_enabled')` | **EXISTS** | Reuse as-is | — |
| `EntityLogService::record()` + `LogSubjectType::System` | **EXISTS** | Reuse; add `LogAction::SystemReset` case (no migration) | XS |
| `GET /api/system/reset/preview` + controller + resource | **MISSING** | Build (thin controller → service `previewCounts()`) | S |
| `SystemResetService` (category→cleaner map, allow-list, FK order, prereq validation, per-category tx, audit) | **MISSING — the core** | Build in `app/Support/` (see boundary decision below) | **M** |
| `ResetResultData` / `ResetPreviewData` DTOs (`final readonly`) | MISSING | Build | XS |
| `SystemResetResource` / `SystemResetPreviewResource` | MISSING | Build (hand-written) | XS |
| `LogAction::SystemReset` enum case | MISSING | Add one case | XS |
| Tests (Feature: 403 non-admin/disabled, 422 bad phrase/prereq, 429 throttle, 200 happy + partial; Unit: allow-list never touches black-list tables, FK order, prereq matrix, audit row written after logs-wipe) | MISSING | Build — **this is where most of the L-effort review attention goes** | **M** |

**Overall verdict: M** (medium). Most infra (auth gate, config flag, audit sink, FormRequest skeleton,
confirmation-phrase pattern) already exists. The genuinely new, careful work is the `SystemResetService`
(category cleaner map + allow-list safety + FK/prereq logic + per-category transaction + post-hoc audit)
and its thorough test matrix. No new packages. No migrations except a zero-risk enum case.

### Boundary decision — where does `SystemResetService` live? (backend-standard §3)

**Recommendation: `app/Support/System/SystemResetService.php` (`App\Support\System`), NOT a domain.**

- The operation is **inherently cross-domain** — it orchestrates deletion across Sales, Crm, Activity,
  Contracts, Catalog, Log, Automation. No single domain owns "reset the whole system". This is exactly
  the "cross-cutting with no single owner" case that `app/Support/` exists for (backend-standard §2 —
  same rationale as `AiRetryService`, `GotenbergClient`).
- **BUT it must not touch other domains' tables directly** (backend-standard §3 is non-negotiable).
  So `SystemResetService` does NOT run `Deal::query()->delete()`. Instead each owning domain exposes a
  **public `purgeAll()` method on its existing Service**. **AS-BUILT seams** (all landed):
  `Sales\DealService::purgeAll(): array` · `Crm\ContactService::purgeAll(): array` ·
  `Crm\CompanyService::purgeAll(): array` · `Activity\ActivityService::purgeAll(): array` ·
  `Contracts\DocumentService::purgeAll(): array` · `Automation\AutomationService::purgeAll(): int` ·
  and the six `directories` seams: `Crm\CrmDirectoryPurgeService` · `Crm\TagService` ·
  `Crm\CustomFieldService` · `Catalog\ProductService` · `Sales\LostReasonService` ·
  `Contracts\MessageTemplateService` (all `::purgeAll(): array`). `entity_logs` is cleared by
  `App\Support\System\LogPurger` (backend-architect-owned, Log has no domain agent).
  `SystemResetService` is a **thin orchestrator** that maps category → owning-domain-service purge call,
  applies FK ordering + per-category transaction + audit. Each domain still owns deletion of its own
  tables; Support only sequences them.

  > **Return-shape convention (as-built):** a single-parent category's purger may return `int` (the
  > parent count) OR a per-table `array<string,int>`; the orchestrator reduces it to the representative
  > parent-table count. A **composed** category (`directories`, no single parent) sums ALL rows across
  > all its seams' arrays — the honest total-dictionary-rows-wiped number.
  >
  > **Missing-seam tolerance:** the map resolves purgers lazily by class-string; a category whose
  > `purgeAll()` is not present resolves to null and is reported in `failed[]` (not a fatal). `finance`
  > maps to no seam by design (greenfield no-op, count 0, not reported as failed).

  > This is the same shape as `DealService` orchestrating via injected services (backend-standard §3
  > canonical example) — cross-domain via public Service methods only, never foreign tables.

- The per-domain `purgeAll()` methods are **small additions** owned by each domain's specialist
  (`sales-backender`, `crm-backender`, etc.) — `backend-architect` wrote this contract + the two
  dictionary-hole seams that had no domain owner queued (`Sales\LostReasonService::purgeAll`,
  `Contracts\MessageTemplateService::purgeAll` — thin, live in their owning domains, called by the
  orchestrator). That is the intended contract-before-implementation sequencing.

---

## 8. Frontend work items surfaced by this contract (for `frontend-specialist`)

1. Drive checkbox counts + `requires` from `GET /api/system/reset/preview`, not the mock `S_CATS`.
2. Implement **prerequisite-aware** checkbox logic (§3): selecting `companies` must auto-select /
   require `deals`+`contacts`; the API is the source of the `requires` map. Currently `ResetTab` allows
   any combination — this will 422 without FE prereq logic.
3. Change confirmation word to `СБРОСИТЬ` (already matches `S_CONFIRM_WORD`).
4. On success, refresh preview counts (don't re-login — selective reset keeps the session).
5. On `200` with `failed[]` non-empty, show a partial-success state, not a plain success toast.

---

## 9. Product decisions (all RESOLVED — accepted by user as the defaults below)

- **P1 — RESOLVED (A).** `POST /api/system/reset` repurposed to selective; the full-wipe
  `app:reset-clean` stays CLI-only (no HTTP surface). Confirmation phrase changed to `СБРОСИТЬ`.
- **P2 — RESOLVED (hard-block 422).** A selected category whose prerequisites are not co-selected is
  rejected 422 before any deletion; nothing is deleted on conflict.
- **P3 — RESOLVED (preserve counters).** `docs` deletes only document instances; number-sequence
  counters (`contract_number_sequences`, `certificate_number_sequences`, per-document numbering) are
  **preserved** (config-like, numbering continuity). They are in the §2 never-delete allow-list gap by
  omission — no cleaner references them.
- **P4 — RESOLVED (exclude `forms`).** `automations` wipes `pipeline_automations` + `automation_runs`
  only; webforms are integration config, untouched.
- **P5 — RESOLVED (exclude FX).** `catalog_exchange_rates` is **NOT** in the `directories` seam set —
  FX history is config; past deals carry snapshot rates so they stay correct, but the rate table itself
  survives. (Deviates from the design copy «курсы валют» — signed off.)
- **P6 — RESOLVED (best-effort per-category).** Each category runs in its own `DB::transaction()`; a
  failing category rolls back alone and is reported in `failed[]`; earlier categories stay committed.
- **P7 — RESOLVED (finance no-op).** `finance` maps to zero seams → `count: 0`, key accepted as a
  no-op, never reported as `failed`. Real cleaners wire in when `Domain/Finance` ships (allow-list
  keeps it safe — nothing is swept in implicitly).
- **P8 — RESOLVED (exclude `certificates` from `docs`).** Certificates are **Onboarding** data
  (course-completion certs), and Onboarding is not one of the 9 categories. `DocumentService::purgeAll`
  deliberately does not touch `certificates`/`certificate_number_sequences` — a future Onboarding-scoped
  reset (if ever desired) would be its own category, not folded into `docs`.
- **P9 — OPEN / documented gap (`crm_folders_and_files` not wiped).** The design's `docs` label is
  «Документы и файлы», but Crm files (`crm_folders_and_files`) are **not** purged today: they carry
  physical-storage side-effects (uploaded blobs on disk/S3) and Crm exposes no `purgeAll` seam for them.
  Under the allow-list this is safe (a table with no cleaner is never touched) — but the `docs` count +
  wipe cover Contracts documents only. **To close:** add `Crm\CrmFileService::purgeAll()` (delete rows
  **and** the backing storage objects) + register it as a second `docs` seam. Flagged for a follow-up;
  needs product confirmation that a data-reset should also delete physical uploaded files.

---

## 10. Safety invariant summary (the non-negotiables)

1. **Allow-list, not deny-list** — a table is deletable ONLY if a category cleaner explicitly names it
   (§2). New tables default to protected.
2. **Never touch** users / tokens / roles / permissions / visibility / departments / pipelines /
   templates / licensor / migration bookkeeping (§2).
3. **Admin-only** via `system-reset` spatie permission — not director, no inline role check (§6.2).
4. **Confirmation phrase** `СБРОСИТЬ` validated server-side in FormRequest (§6.2).
5. **`config('system.reset_enabled')`** gate — off by default (§6).
6. **Rate-limited** `throttle:3,1` (§6.2).
7. **Prerequisite validation before any delete** — clean 422, nothing deleted on FK-conflict (§3).
8. **Per-category transaction** + per-category count report (§5).
9. **Mandatory audit row** to `entity_logs` (`LogSubjectType::System`, `LogAction::SystemReset`),
   written AFTER deletions incl. after a `logs` self-wipe (§4).
10. **Cross-domain only via owning-domain `purgeAll()` Service methods** — Support orchestrates, never
    touches foreign tables (§7 boundary decision).
