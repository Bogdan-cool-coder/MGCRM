# Backend Standard — MACRO Global CRM (canonical stack reference)

> **Status: canonical.** Together with `ARCHITECTURE.md` this file is the SINGLE source of
> truth for the backend stack. **Vizion (`examples/vizion/`) is retired as a stack reference
> — do not consult it for patterns.** Every rule below is grounded in OUR own shipped code,
> with `file:line` anchors that ARE the canonical example. When a pattern is ambiguous, copy
> the referenced file, not Vizion.
>
> **How this relates to `ARCHITECTURE.md`:** `ARCHITECTURE.md` is the terse law (the "what");
> this file is the worked reference (the "how", with our own code as the example). If the two
> ever disagree, `ARCHITECTURE.md` wins and this file is corrected via `product-manager`.

---

## 0. First principles

1. **Library-first.** Any feature is built on an already-integrated library, not hand-rolled.
   New package only by explicit approval (§7 registry lists what we already have).
2. **Layers are not optional.** The request path in §1 is the only allowed path. Skipping a
   layer (Controller→Model direct, raw array response, inline validate) is a bug, not a style.
3. **DDD boundaries are hard.** Domain code lives in `app/Domain/<Context>`; cross-domain calls
   go through the owning context's public Service only (§3).
4. **Our code is the example.** For anything already built, the canonical pattern is the
   referenced file here — `Sales/DealService`, `Iam/VisibilityResolver`, the Tag slice.

---

## 1. Request → response layering (NON-NEGOTIABLE)

The only allowed request path:

```
HTTP → Route (/api, auth:sanctum)
     → FormRequest        validation + (optional) request authorization
     → Controller         THIN: parse validated input → call ONE service method → return Resource
     → Domain Service     ALL business logic, ALL DB queries, transactions, orchestration
     → Eloquent Model     fillable/hidden, casts(), relations, scopes — NO business logic
     → API Resource       response shape — hand-written JsonResource, NEVER a raw array
     → Policy             authorization, resolved through VisibilityResolver (§4)
```

**Hard rules per layer:**

| Layer | Must | Must NOT |
|---|---|---|
| Controller | be thin: validated input → 1 service call → Resource; `declare(strict_types=1)`; constructor-inject the Service; `$this->authorize('ability')` for gate checks | run Eloquent queries, hold business conditions, build raw response arrays, contain loops over data |
| FormRequest | hold ALL validation in `rules()`; `messages()` for custom copy | — |
| Service | hold ALL DB queries + business logic; `DB::transaction()` here; constructor DI | use facades (except `DB`/`Log` where trivial) |
| Model | `$fillable`/`$hidden` as properties, typed `casts()`, relations, scopes | business logic, side effects |
| Resource | hand-written `toArray()`, `/** @mixin Entity */` | `spatie/laravel-data`; returning models raw |

**Forbidden (black list):** inline `$request->validate([...])`; `return response()->json([raw array])`;
returning an Eloquent model straight from a controller; `if ($user->role === ...)` anywhere outside
a Policy/Gate; skipping the Service layer.

### 1.1 Canonical worked example — the `crm_tags` slice (shipped 2026-07-01)

The cleanest end-to-end CRUD reference. Trace it in build order:

| Step | Canonical file | Anchor |
|---|---|---|
| Migration | `src/database/migrations/2026_07_01_100000_create_crm_tags_table.php` | reversible up/down, indexed |
| Model | `src/app/Domain/Crm/Models/Tag.php` | `protected $table='crm_tags'`, `casts()` at :25-31 — no logic |
| FormRequest (store) | `src/app/Http/Requests/Crm/StoreTagRequest.php` | `authorize()=true` :12-15 (gate lives in controller), `rules()` :17-26 |
| FormRequest (update) | `src/app/Http/Requests/Crm/UpdateTagRequest.php` | |
| Service | `src/app/Domain/Crm/Services/TagService.php` | `list()/create()/update()/delete()` — ALL queries here :22-72 |
| Controller | `src/app/Http/Controllers/Crm/Admin/TagController.php` | thin; `$this->authorize('admin-write')` on writes :48,57,67; returns `TagResource` |
| Resource | `src/app/Http/Resources/Crm/TagResource.php` | manual `toArray()` :14-25 |
| Routes | `src/routes/api.php:436-437, 509` | read = any auth user; write = `apiResource` behind admin-write |

> The Tag slice is fully canonical as of backlog #20 (2026-07-02): `TagService.php:38` was updated to
> `whereLikeCi('name', $search)` — the deviation noted here is closed.

For anything involving **row-level visibility, money, or cross-domain orchestration**, the mature
reference is `src/app/Domain/Sales/Services/DealService.php` + `Sales/Policies/DealPolicy.php` +
`Iam/Services/VisibilityResolver.php`.

---

## 2. DDD folder map (the FULL set actually in use)

Domain code lives strictly in `src/app/Domain/<Context>/…`. 14 live contexts:
`Activity, Automation, Catalog, Contracts, Crm, Iam, Inbox, Log, Migration, Notification, Onboarding,
Org, Sales, SalesPulse` (greenfield: `CustomerSuccess`, `Finance` — created at sprint start).

**Canonical minimum (every context):**

| Folder | Holds |
|---|---|
| `Models` | Eloquent models (fillable/hidden/casts/relations/scopes only) |
| `Services` | all business logic + DB queries; one `<Name>Service` per concern |
| `Enums` | PHP backed enums (statuses, types) |
| `Data` | plain `final readonly` DTOs (§5) |
| `Jobs` | queued jobs |
| `Policies` | per-model authorization |

**Extensions actually in use (add per domain when needed — NOT a violation):**

| Folder | Used by | Purpose |
|---|---|---|
| `Events` | Activity, Contracts, Onboarding, Sales | domain events dispatched after commit |
| `Listeners` | Activity, Automation, Crm, Notification, Onboarding | event handlers (queue jobs / write DB rows only) |
| `Actions` | Automation | tagged action-handlers (`automation.actions`) fed to the dispatcher |
| `Exceptions` | Automation, Sales | domain-specific exceptions (e.g. `WonGateException`) |
| `Support` | Automation, Migration, Notification, SalesPulse | context-local helpers/value objects |
| `Contracts` | SalesPulse | interfaces / seams for DI (e.g. `PulseLlmClient` — swap real for fake in tests) |
| `Renderers` | SalesPulse | output renderers |
| `Telegram` | Notification, SalesPulse | bot handlers / bot factory (Nutgram) |
| `Extractors` / `Loaders` / `Transformers` | Migration | AMO ETL sub-layers |

> The `Contracts` folder here means **PHP interfaces / DI seams**, NOT the `Contracts` *domain*
> (`Domain/Contracts` = документооборот). Do not confuse them.

**Non-DDD shared infra — consolidated under `app/Support/` (blessed exception).** Truly
cross-cutting infra with no single owning domain lives in `src/app/Support/`, NOT in a domain
context and NOT in a parallel `app/Services/` tree. There is **no `app/Services/`** — the two
Vizion-holdover services that used to sit there were relocated into `app/Support/` (backlog #21,
2026-07-02) so every shared seam lives under one roof:

| Class | Canonical location | Namespace | Consumers (why it's shared, not domain-owned) |
|---|---|---|---|
| AI-cascade retry | `src/app/Support/Ai/AiRetryService.php` | `App\Support\Ai` | `Contracts/TemplateCheckService`, `Onboarding/{QuizGenerationService, AiTutorService}`, `SalesPulse/PrismPulseLlmClient` — 4 consumers / 3 domains; no single owner |
| DOCX/HTML→PDF client | `src/app/Support/Documents/GotenbergClient.php` | `App\Support\Documents` | `Contracts/{ContractGenerationService, TemplateCheckService}`, `Onboarding/CertificateService` — 2 domains; no single owner |
| Safe-LIKE escaper | `src/app/Support/LikeEscape.php` | `App\Support` | query-builder macros (§6.1) |

Do not scatter copies into contexts; inject and call these. A helper used by **exactly one** domain
belongs in that domain's `Support` subfolder (§2 table), not here — `app/Support/` is for the
genuinely cross-cutting-with-no-owner case only.

---

## 3. Domain boundaries (NON-NEGOTIABLE)

- **Cross-domain access is ONLY through the owning context's public Service method.**
  Never touch another domain's Model / table directly.
- **Canonical example:** `DealService` orchestrates across contexts entirely via injected
  Services — `ActivityService`, `ExchangeRateService`, `DocumentService`, `CompanyRequisiteService`,
  `CustomFieldService`, `EngagementService`, `EntityLogService`
  (`src/app/Domain/Sales/Services/DealService.php:11-24` imports; `:95-101` constructor DI;
  `:115` `$this->engagementService->touchForDeal(...)`). It never does
  `FinInvoice::where(...)` or reads a foreign table.
- **Status machines:** enum + a transition guard in the service (`assertCanTransition(from,to)`);
  no magic status strings anywhere in code.
- **Events over reaching in:** side effects in other contexts are triggered by dispatching a domain
  Event and letting the owning context's Listener react (wired in `AppServiceProvider::boot()`),
  not by a service reaching into another domain's write path.

---

## 4. Authorization & IAM-1 (CLOSED)

**IAM-1 is closed and shipped.** spatie/laravel-permission is the single authoritative authz store,
running on the **`sanctum` guard**:

- `role` is **not a column** — it is a virtual accessor over the user's single spatie role, with a
  buffered mutator + `saved` hook that syncs the spatie grant
  (`src/app/Domain/Iam/Models/User.php:47, 137-198`).
- Default guard = `sanctum` (`src/config/auth.php:18-27`); Sanctum guard list forces Bearer-only auth
  (`src/config/sanctum.php:48` → `'guard' => []`).
- Global abilities (`admin-write`, `dedup-scan-all`, `system-reset`, `view-manager-cabinet`) are
  spatie permissions auto-registered as Gate abilities via PermissionRegistrar — so
  `can:admin-write` middleware, `$this->authorize('system-reset')`, `$user->can('dedup-scan-all')`
  all resolve through the `role_has_permissions` matrix
  (`src/app/Providers/AppServiceProvider.php:247-255`).
- Per-model Policies for every domain model, registered explicitly via `Gate::policy(...)`
  (`AppServiceProvider.php:182-245`) — explicit registration is the house choice (keeps the section
  exhaustive), not auto-discovery.

**Rules:**
- **No inline role checks.** `if ($user->role === 'admin')` in a controller/service is a bug. Role
  logic lives only inside a Policy/Gate; permissions resolve via `$user->can()`.
- **Row-level visibility** is a first-class subsystem (`Iam/Services/VisibilityResolver`):
  - `applyScope(Builder $query, User $user, array $ownerColumns, ?string $departmentColumn = null, ?VisibilityScope $scope = null): Builder`
    — the reusable query-scoper every list/export service calls
    (`src/app/Domain/Iam/Services/VisibilityResolver.php:89-119`).
  - `All` → no filter; `Own` → owner match (owner columns OR-ed); `Department` → dept subtree OR own
    (LIVE since M9 for tables with a dept anchor; degrades to `Own` where none — never to `All`).
  - Policies resolve the same scope so read and write can never diverge
    (`src/app/Domain/Sales/Policies/DealPolicy.php:97-118`).
  - Dept-subtree BFS is single-sourced in `VisibilityResolver::departmentSubtreeIds()` (:150-170) —
    shared by query layer and policy layer. **Never hand-roll a department/owner scope branch** —
    call `applyScope()` / `departmentSubtreeIds()`.
  - `ResolveVisibility` middleware (`src/app/Http/Middleware/ResolveVisibility.php`) only STAMPS
    `visibility_scope` on the request as a convenience carrier; enforcement is always in the service
    via `applyScope()`.
- The scope matrix per role is admin-editable (`visibility_settings` via `VisibilityConfigService`);
  an unseeded table falls back to legacy `VisibilityScope::forRole` defaults (tests reproduce
  historical behavior exactly).

**Auth transport:** Sanctum Bearer personal access tokens (`createToken('api')->plainTextToken`),
FormRequest → `AuthService` → `UserResource` (`src/app/Http/Controllers/Auth/AuthController.php:37-98`).
TOTP 2FA (`pragmarx/google2fa`, singleton in `AppServiceProvider.php:141`): login → temp token →
`/2fa/validate` → full token; `totp_secret` + `backup_codes` are encrypted casts + `$hidden`
(`User.php:79-100`). Brute-force lockout is failures-only via `Iam/Services/LoginThrottle` inside the
controller (NOT a route throttle — a route throttle counts successful logins too).

---

## 5. Invariants (always true)

| Invariant | Rule | Canonical anchor |
|---|---|---|
| **Money = integer kopecks** | all money is `unsignedBigInteger` kopecks in DB, cast `'integer'`; formatting (rubles, separators) only on the frontend. `float`/`decimal` for money is forbidden. `decimal` is allowed ONLY for quantities/percentages. | `src/app/Domain/Sales/Models/Deal.php:103,116`; `2026_06_28_120010_add_payment_fields_to_deals_table.php:27` (`decimal` OK for `deal_products.quantity`) |
| **Manual API Resources** | responses via hand-written `JsonResource`; `spatie/laravel-data` is forbidden. | `src/app/Http/Resources/Crm/TagResource.php` |
| **`final readonly` DTOs** | domain DTOs are plain `final readonly` classes (no spatie/laravel-data). 22 DTOs, none import Spatie. | `src/app/Domain/Automation/Data/ExecuteNowResult.php:24` |
| **No Horizon** | queues on `redis`; Event → Listener → queued Job for async. | `src/config/queue.php:16` |
| **Migrations** | reversible up/down; FK `->constrained()->cascadeOnDelete()`/`nullOnDelete()`; translatable → `jsonb`; index hot WHERE/ORDER BY; name `YYYY_MM_DD_HHMMSS_<verb>_<entity>`; both `migrate` + `migrate:rollback` pass on pgsql before commit. Guard raw PG DDL with `DB::getDriverName()==='pgsql'` so the sqlite suite survives. | — |
| **`env()` only in config** | code reads `config('crm.x')` / `config('ai.x')`, never `env()`. | — |
| **Test isolation** | PHPUnit + SQLite `:memory:`, triple isolation: `phpunit.xml force="true"` + `TestCase::createApplication()` putenv-before-bootstrap + `setUp()` sqlite abort-guard. Tests NEVER hit the live DB. Extend `Tests\TestCase` — it auto-seeds the spatie role/permission matrix (`seedRolesAndPermissions()`) and auto-binds `FakeGotenbergClient` (`fakeGotenbergByDefault()`) so PDF side-effects never open a socket. Use `flushAuth()` when switching Bearer tokens mid-test. Pest is forbidden. | `src/tests/TestCase.php`; `src/phpunit.xml:32-38` |
| **Feature per endpoint, Unit per Service** | AI/HTTP mocked. Gotenberg fake bound by default; HTTP-layer tests construct the real client + own `Http::fake()`. | `ARCHITECTURE.md §6` |

---

## 6. Reuse checklist (MANDATORY — closes known duplication)

Before writing new code, use these. `product-manager` cuts hand-rolled code that duplicates a
listed facility.

### 6.1 Safe LIKE search — **MANDATORY, no exceptions**

Any user-input "contains" search MUST use the query-builder macros — **never** hand-roll
`where(col, 'like', '%'.$x.'%')`.

- `whereLike($col, $val)` / `orWhereLike($col, $val)` — wildcard-safe LIKE; escapes `%` `_` `\`
  and emits `LIKE ? ESCAPE ?`.
- `whereLikeCi($col, $val)` / `orWhereLikeCi($col, $val)` — case-insensitive (Postgres `ILIKE`;
  SQLite `LOWER() LIKE` fallback for Cyrillic). Use for search fields (full_name, email, phone,
  company name, tax_id).
- Backed by `App\Support\LikeEscape` (`ESCAPE_CHAR` + `wrap()`); macros registered in
  `src/app/Providers/AppServiceProvider.php:352-410`.

**Correct adopters (copy these):** `Activity/Services/ActivityService`, `Crm/Services/CompanyService`,
`Crm/Services/ContactService`, `Sales/Services/DealService`.
**Known deviations — CLOSED (backlog #20, 2026-07-02):** `TagService` `:38`, `ProductService` `:30-34`,
`CourseService` `:36-38` all migrated to `whereLikeCi`/`orWhereLike` macros. No remaining known deviations.

### 6.2 Other mandatory reuse

- **Row-level visibility** → `VisibilityResolver::applyScope()` / `departmentSubtreeIds()`. Never
  hand-roll an owner/department scope branch.
- **Cross-cutting AI** → `App\Support\Ai\AiRetryService` + `config/ai.php` (`executeWithRetry` /
  `executeWithRetryAndToolChoice` for forced tool_use). Never call a provider SDK directly.
- **DOCX→PDF** → `App\Support\Documents\GotenbergClient` (+ PHPWord). Never hand-roll headless Chrome.
- **Excel export** → PhpSpreadsheet. Never hand-assemble xlsx.
- **DTOs** → plain `final readonly` classes. **API responses** → hand-written `JsonResource`.
- **Translatable fields** → `spatie/laravel-translatable` + `jsonb` column + `protected array $translatable`.

---

## 7. Library registry (do NOT add new packages)

Everything below is already integrated. For task X, use lib Y — new packages only by explicit
approval.

| Task | Use (already integrated) | Do NOT add |
|---|---|---|
| AuthN tokens | `laravel/sanctum` (Bearer) | passport, fortify |
| TOTP 2FA | `pragmarx/google2fa` (singleton in AppServiceProvider) | any other 2FA lib |
| Roles / permissions | `spatie/laravel-permission` on `sanctum` guard | custom RBAC, casbin |
| Row-level visibility | `Iam/Services/VisibilityResolver::applyScope()` | inline owner/dept filters |
| Safe LIKE search | `whereLike`/`whereLikeCi` macros + `App\Support\LikeEscape` | raw `'like','%'.$x.'%'` |
| DTOs | plain `final readonly` classes | spatie/laravel-data |
| API responses | hand-written `JsonResource` | spatie/laravel-data, raw arrays |
| Translatable fields | `spatie/laravel-translatable` + jsonb | |
| AI cascades | Prism via `App\Support\Ai\AiRetryService` + `config/ai.php` | direct SDK calls |
| DOCX→PDF | `App\Support\Documents\GotenbergClient` (+ PHPWord) | hand-rolled headless Chrome |
| Excel export | PhpSpreadsheet | hand-built xlsx |
| Queues | redis (`config/queue.php`) | Horizon |
| Telegram bot | Nutgram (`config/nutgram.php`; SalesPulse runs a second bot instance) | |
| Error tracking | `sentry/sentry-laravel` (`send_default_pii=false`) | |
| Backups | `spatie/laravel-backup` | |
| Tests | PHPUnit + SQLite `:memory:`, extend `Tests\TestCase` | Pest |

---

## 8. Naming (fixed)

| Thing | Pattern |
|---|---|
| Controller | `<Entity>Controller` (`app/Http/Controllers/<Context>`) |
| Service | `<Name>Service` (`Domain/<Context>/Services`) |
| FormRequest | `<Action><Entity>Request` (`app/Http/Requests/<Context>`) |
| API Resource | `<Entity>Resource` (`app/Http/Resources/<Context>`) |
| Enum | `<Name>` backed enum (`Domain/<Context>/Enums`) |
| Policy | `<Entity>Policy` (`Domain/<Context>/Policies`) |
| Event / Listener | `<Thing><Verb>` / `<Verb><Thing>Listener` |
| Migration | `YYYY_MM_DD_HHMMSS_<verb>_<entity>.php` |

---

## Appendix — evidence index

- Layered auth controller: `src/app/Http/Controllers/Auth/AuthController.php:37-98`
- Tag CRUD slice: `Domain/Crm/{Models/Tag.php, Services/TagService.php}`, `Http/{Requests/Crm/StoreTagRequest.php, Controllers/Crm/Admin/TagController.php, Resources/Crm/TagResource.php}`
- Cross-domain via Service only: `Domain/Sales/Services/DealService.php:11-24, 95-101, 115`
- IAM-1 closed (User model): `Domain/Iam/Models/User.php:47, 137-198`
- Sanctum-guard authz: `config/auth.php:18-27`, `config/sanctum.php:48`, `AppServiceProvider.php:247-255`
- Visibility subsystem: `Domain/Iam/Services/VisibilityResolver.php:89-170`, `Domain/Sales/Policies/DealPolicy.php:97-118`
- Money kopecks: `Domain/Sales/Models/Deal.php:103,116`
- LIKE-escape macros: `AppServiceProvider.php:352-410`; helper `app/Support/LikeEscape.php`
- Test isolation: `tests/TestCase.php`, `phpunit.xml:32-38`
- Full divergence audit (why Vizion was retired): `docs/audit/Backend-Divergence-Vizion-2026-07.md`
