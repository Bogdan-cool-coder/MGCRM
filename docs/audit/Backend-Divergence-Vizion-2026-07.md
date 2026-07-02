# Backend Divergence Audit — MGCRM vs Vizion (2026-07)

> **Purpose.** Empirically measure how far the MGCRM backend has drifted from its origin
> template (`examples/vizion/`), and distill the patterns we *actually* build to today, so we
> can move to a layered source-of-truth: (1) Laravel + approved libs for primitives,
> (2) our own `ARCHITECTURE.md` + `src/app/Domain/*` for composition, (3) Vizion demoted to
> origin/tiebreaker only.
>
> **Method.** Read-only comparison of `examples/vizion/src/app` vs `src/app`, `src/config`,
> `src/tests`, `src/database/migrations`, `src/routes`. `file:line` refs on both sides.
> Ratings: `aligned` / `diverged` / `new-in-ours` / `dropped-from-vizion`.

---

## Headline verdict

**We have drifted decisively away from Vizion — call it ~70–75% divergent at the
composition layer, while remaining ~90% aligned at the infrastructure/isolation layer.**

The split is the whole story:

- **Infrastructure primitives** we kept almost verbatim: the test triple-isolation harness,
  the Sanctum Bearer flow shape, the `config/ai.php` Prism cascade skeleton, the migration
  conventions (kopecks, reversible, FK constraints). Here Vizion is still a faithful ancestor.
- **How we compose a feature** has diverged so far that Vizion is actively *misleading* as a
  pattern reference. Vizion's own controllers do everything `ARCHITECTURE.md` forbids: inline
  `$request->validate()`, raw `response()->json()`, returning Eloquent models directly from
  controllers, inline `if ($user->role !== 'superadmin')` role checks, and Eloquent queries in
  controllers — with **no Service layer, no FormRequest, no API Resources, and no Policies at all**
  (`examples/vizion/src/app/Http/Controllers/CompanyController.php:29-130`,
  `examples/vizion/src/app/Http/Controllers/Auth/AuthController.php:13-32`).

We are ~6x larger by surface (356 API routes in `src/routes/api.php` vs 60 in
`examples/vizion/src/routes/api.php`), we have a full DDD tree Vizion never had, a
permission/visibility authorization stack Vizion has no analogue for, and we have
*already closed* the IAM-1 debt that `ARCHITECTURE.md` still describes as open.

**Practical conclusion:** Vizion should be demoted to "origin + tiebreaker for not-yet-built
primitives" exactly as leadership proposed. For anything already built (auth layering, authz,
CRUD slices, DTOs, testing), the canonical reference is *our own code* — `Sales/DealService`,
`Iam/VisibilityResolver`, the Tag slice — not Vizion.

---

## 1. Divergence map

### 1.1 Request → response layering — **DIVERGED (we are stricter than Vizion)**

| | Vizion | Ours |
|---|---|---|
| Validation | inline `$request->validate([...])` in the controller (`CompanyController.php:47-55`, `AuthController.php:15-18`) | **FormRequest only** (`src/app/Http/Requests/Crm/StoreTagRequest.php:17-26`, `Auth/LoginRequest`) |
| Controller | fat: role checks, Eloquent queries, raw arrays (`CompanyController.php:31-38, 60-74`) | **thin**: parse request → one service call → Resource (`src/app/Http/Controllers/Crm/Admin/TagController.php:32-71`, `Auth/AuthController.php:37-98`) |
| Business logic | in controller / model | **Domain Service** (`src/app/Domain/Crm/Services/TagService.php`, `src/app/Domain/Sales/Services/DealService.php`) |
| Response | `return $company;` / `response()->json([raw array])` (`CompanyController.php:38, 57, 73, 112`) | **API Resource always** (`src/app/Http/Resources/Crm/TagResource.php`, `UserResource`) |

**Verdict:** This is the single biggest divergence, and it is *intentional and healthy*.
Our `ARCHITECTURE.md §1` layering is real and consistently enforced in current code; Vizion
never followed it. Vizion is not a valid pattern reference for request handling. Note the
`ARCHITECTURE.md` header line 5 that says "if a pattern diverges from Vizion, Vizion is right"
is now false for this whole area (see §3).

### 1.2 DDD folder organization — **NEW-IN-OURS + DRIFTED past the documented set**

- Vizion: flat `app/Models/*` (15 models), `app/Services/{AI,Documents,MacroData}`, `app/Http/Controllers/*` — **no `Domain/` folder at all** (`examples/vizion/src/app/` has no `Domain`).
- Ours: 14 DDD contexts under `src/app/Domain/<Context>` (Activity, Automation, Catalog, Contracts, Crm, Iam, Inbox, Log, Migration, Notification, Onboarding, Org, Sales, SalesPulse).

**Drift within our own rule:** `ARCHITECTURE.md §2` line 52 declares the canonical context
layout as `{Models,Data,Enums,Services,Jobs,Policies}`. Reality has grown **11 additional
sub-folder kinds** that the doc never lists:

- `Events` (Activity, Contracts, Onboarding, Sales)
- `Listeners` (Activity, Automation, Crm, Notification, Onboarding)
- `Actions` (Automation)
- `Exceptions` (Automation, Sales)
- `Support` (Automation, Migration, Notification, SalesPulse)
- `Contracts` (SalesPulse — interfaces/seams)
- `Renderers` (SalesPulse)
- `Telegram` (Notification, SalesPulse)
- `Extractors` / `Loaders` / `Transformers` (Migration ETL)

These are all *sensible* (events/listeners are core Laravel; ETL sub-layers are domain-appropriate),
but the documented folder set is stale. **Verdict: `new-in-ours`, and `ARCHITECTURE.md §2` needs
its folder list expanded (see §3).**

**Non-DDD holdovers (Vizion residue):** `src/app/Services/AI/AiRetryService.php` and
`src/app/Services/Documents/GotenbergClient.php` still live under the flat Vizion-style
`app/Services/` path rather than a `Domain/` context. Referenced from `src/tests/TestCase.php:7`
(`use App\Services\Documents\GotenbergClient;`) and `AppServiceProvider`. These are the last two
files that look like Vizion. `dropped-from-vizion` structurally elsewhere, but these two are
`aligned-with-vizion` by accident of not being moved.

### 1.3 Auth (Sanctum) & 2FA — **ALIGNED in transport, DIVERGED (improved) in layering; 2FA is NEW**

- **Token model — aligned:** both issue `createToken('api')->plainTextToken` Bearer tokens and store token client-side. Vizion `AuthController.php:30`; ours via `AuthService::issueApiToken` (`src/app/Http/Controllers/Auth/AuthController.php:69`).
- **Layering — diverged (improved):** Vizion login is inline-validate + raw-json (`AuthController.php:13-32`). Ours is FormRequest → `AuthService` → `UserResource` with `->additional([...])` for the token (`AuthController.php:37-75`).
- **2FA — new-in-ours:** Vizion has **no 2FA**. We built the full TOTP flow (temp-token → `/2fa/validate` → full token) on `pragmarx/google2fa`, singleton-bound in `AppServiceProvider.php:141`, with encrypted `totp_secret` + `backup_codes` casts + `$hidden` (`src/app/Domain/Iam/Models/User.php:79-100`). Sourced from `examples/contracts/` semantics, not Vizion.
- **Brute-force lockout — new-in-ours:** `Iam/Services/LoginThrottle` (failures-only, cleared on success), applied inside the controller (`AuthController.php:43,53,58`), deliberately *not* a route throttle (`AppServiceProvider.php:278-283`). No Vizion analogue.

### 1.4 Authorization (Policy/Gate vs role-enum; the IAM-1 debt) — **DIVERGED + IAM-1 NOW CLOSED**

This is the most important *status* finding: **IAM-1 is done, but the docs still say it is open.**

- Vizion: pure inline role strings — `if ($request->user()->role !== 'superadmin')` scattered through controllers (`CompanyController.php:31, 43, 64, 83, 119`). No Policies, no permissions, `role` is a plain string column.
- Ours (current, verified):
  - `role` is **no longer a column** — it is a virtual accessor over the single spatie role, with a buffered mutator + `saved` hook that syncs the spatie grant (`src/app/Domain/Iam/Models/User.php:47, 137-198`).
  - spatie/laravel-permission runs on the **`sanctum` guard** — default guard set to `sanctum` (`src/config/auth.php:18-27`), Sanctum guard list forces Bearer-only auth (`src/config/sanctum.php:48` → `'guard' => []`).
  - Global abilities (`admin-write`, `dedup-scan-all`, `system-reset`, `view-manager-cabinet`) are **no longer Gate closures over `$user->role`** — they are spatie permissions auto-registered as Gate abilities via the PermissionRegistrar (`AppServiceProvider.php:247-255` comment block documents the switch). `can:admin-write` middleware, `$this->authorize('system-reset')`, and `$user->can('dedup-scan-all')` all resolve through the spatie matrix now.
  - Per-model Policies exist for **every domain model** (~35 `Gate::policy()` registrations, `AppServiceProvider.php:182-245`).
  - Row-level visibility is a **first-class subsystem** with no Vizion analogue: `Iam/Services/VisibilityResolver` (`applyScope()` + `departmentSubtreeIds()`, `src/app/Domain/Iam/Services/VisibilityResolver.php`), consumed by Policies (`DealPolicy::canAccess`, `src/app/Domain/Sales/Policies/DealPolicy.php:97-118`) and services, backed by an admin-editable `visibility_settings` matrix (`VisibilityConfigService`).

**Verdict:** `new-in-ours` and `diverged`. **The IAM-1 debt described in `ARCHITECTURE.md §3`
line 64 and `CLAUDE.md` is stale — the migration to spatie-on-sanctum has already shipped.**
(See §3 for the patch.)

One residual policy-registration style note: we register policies **explicitly** via
`Gate::policy(...)` rather than relying on Laravel auto-discovery (`AppServiceProvider.php:182-245`).
This is a deliberate house choice ("keeps the section exhaustive", commented at line 219-220), not
a bug — worth documenting as house-style.

### 1.5 Money handling (kopecks) — **ALIGNED / clean**

- All money is integer kopecks: `unsignedBigInteger` in migrations (`deals.paid_amount` at `2026_06_28_120010_add_payment_fields_to_deals_table.php:27`; `deal_products.amount` at `2026_06_12_120004_create_deal_products_table.php:28`; `salary_plans.personal_income_plan_kopecks` at `2026_06_12_130002_create_salary_plans_table.php:19`; `document_items.line_total`), cast `'integer' // kopecks` (`src/app/Domain/Sales/Models/Deal.php:103, 116`).
- The `decimal(...)` columns that exist are **not money** — they are `quantity` (`deal_products.quantity` 12,2), `qty` (`document_items` 8,3), and `discount_pct` (percentage 5,2). Correct per `ARCHITECTURE.md §3` (money-only-integer rule; quantities/percentages may be decimal).

**Verdict:** `aligned`. No `float`/`decimal` money leaks found.

### 1.6 API Resource conventions (manual, no spatie/laravel-data) — **ALIGNED / clean**

- All responses go through hand-written `JsonResource` subclasses (`src/app/Http/Resources/**`, one per context sub-folder). Example `TagResource.php:14-25`.
- **Zero** use of `spatie/laravel-data` in `Domain/*/Data` — all DTOs are plain `final readonly` classes (`src/app/Domain/Automation/Data/ExecuteNowResult.php:24`; 22 DTOs total, none import Spatie).
- Vizion had *no* Resource layer at all (returned models directly), so this is `new-in-ours` relative to Vizion but exactly what `ARCHITECTURE.md §1` prescribes.

### 1.7 Validation & error localization — **ALIGNED**

- FormRequest everywhere (see §1.1). `messages()` overrides used where custom copy is needed (9 FormRequests).
- Localized error strings via `lang/{ru,en}/{auth,validation,admin,onboarding}.php` and `__()` (`AuthController.php:86` `__('auth.logged_out')`). `SetLocale` middleware copied from Vizion.

### 1.8 Queues / Jobs (no Horizon) — **ALIGNED**

- 14 domain Jobs across contexts (`Domain/*/Jobs`). Queue default `redis` (`src/config/queue.php:16`). **No Horizon** anywhere in `src/`. Event → Listener → queued Job is the async pattern (`AppServiceProvider.php:288-331`).

### 1.9 Config patterns (`ai.php` etc.) — **ALIGNED skeleton, TRIMMED + EXTENDED content**

- `config/ai.php` keeps Vizion's Prism-cascade shape but is **107 lines vs Vizion's 220**: dropped the `glm` provider and `context_overflow_fallback` chat type; added our own chat types `quiz_generation` and `tutor`. `env()` only in config, `config('ai.x')` in code — correct.
- We have **many more configs** than Vizion (`2fa.php`, `amo_migration.php`, `automation.php`, `contracts.php`, `crm.php`, `inbox.php`, `nutgram.php`, `permission.php`, `salespulse.php`, `sentry.php`, `system.php`) — all `new-in-ours`, all following the `config/x.php` + `config('x.y')` convention.

### 1.10 Testing patterns — **ALIGNED (directly descended) + EXTENDED**

- Our `src/tests/TestCase.php` is a **verbatim descendant** of `examples/vizion/src/tests/TestCase.php`: identical `createApplication()` triple-isolation (`putenv` before bootstrap), identical hard sqlite guard in `setUp()`. This is the single most faithfully-copied file in the codebase.
- Extended beyond Vizion with: `seedRolesAndPermissions()` (spatie matrix, required before any factory user), `fakeGotenbergByDefault()` (container-bind fake so side-effect PDF paths never hit the network), and `flushAuth()` (per-request guard reset for multi-token 2FA tests). All `new-in-ours`.
- PHPUnit + SQLite `:memory:`, no Pest. `aligned`.

### 1.11 Naming conventions — **ALIGNED with our own doc, DIVERGED from Vizion**

- `<Entity>Controller`, `<Name>Service`, `<Action><Entity>Request`, `<Entity>Resource`, `<Entity>Policy` — all followed (`TagController`, `TagService`, `StoreTagRequest`, `TagResource`; `DealController`/`DealService`/`DealPolicy`). Matches `ARCHITECTURE.md §5`. Vizion had no Request/Resource/Policy names to compare.

### 1.12 Divergence summary table

| Area | Rating | Note |
|---|---|---|
| Request→response layering | diverged (stricter) | Vizion violates our own §1; not a valid reference here |
| DDD folders | new-in-ours + drifted | 11 folder kinds beyond documented set |
| Sanctum token | aligned | verbatim Bearer flow |
| Auth layering | diverged (improved) | FormRequest + Service + Resource |
| 2FA + brute-force | new-in-ours | no Vizion analogue |
| Authorization / IAM-1 | new-in-ours; IAM-1 CLOSED | spatie-on-sanctum shipped; docs stale |
| Money (kopecks) | aligned | clean |
| API Resources | aligned (new vs Vizion) | manual, no spatie/data |
| Validation/localization | aligned | FormRequest + lang |
| Queues/Jobs | aligned | redis, no Horizon |
| config/ai.php | aligned skeleton | trimmed providers, added chat types |
| Testing harness | aligned + extended | direct descendant + spatie/gotenberg/flushAuth |
| Naming | aligned (our doc) | |

---

## 2. Distilled house-style — "how WE build a backend feature today"

This is extracted from current code, not aspiration. It is the canonical pattern; Vizion is not.

**The layer stack (per feature):**

1. **Migration** (`src/database/migrations/YYYY_MM_DD_HHMMSS_<verb>_<entity>.php`) — reversible `up`/`down`, FK `->constrained()->cascadeOnDelete()|nullOnDelete()`, money as `unsignedBigInteger` kopecks, translatable as `jsonb`, indexes on hot `WHERE`/`ORDER BY`. Guard raw PG DDL with `DB::getDriverName()==='pgsql'` so the sqlite test suite survives.
2. **Model** (`Domain/<Context>/Models/<Entity>.php`) — `declare(strict_types=1)`, `$fillable`/`$hidden` as properties, typed `casts()` method, relations, query-scopes. **No business logic, no side effects.**
3. **Enum** (`Domain/<Context>/Enums`) — PHP backed enum for any status/type; status machines get an `assertCanTransition()` guard in the service.
4. **FormRequest** (`Http/Requests/<Context>/<Action><Entity>Request.php`) — `authorize()` returns `true` when a controller `$this->authorize('ability')` gate handles authz; `rules()` holds all validation.
5. **Domain Service** (`Domain/<Context>/Services/<Name>Service.php`) — constructor-injected deps, ALL DB queries and business logic, `DB::transaction()` here, cross-domain calls only via another context's public Service method.
6. **Policy** (`Domain/<Context>/Policies/<Entity>Policy.php`) — resolves scope via `VisibilityResolver`; registered in `AppServiceProvider::boot()` with explicit `Gate::policy()`.
7. **Controller** (`Http/Controllers/<Context>/<Entity>Controller.php`) — thin: `declare(strict_types=1)`, constructor-inject the Service, one method per action = parse request → one service call → return Resource. `$this->authorize('ability')` for gate checks.
8. **API Resource** (`Http/Resources/<Context>/<Entity>Resource.php`) — `/** @mixin Entity */`, hand-written `toArray()`.
9. **Route** (`src/routes/api.php`) — under `/api` + `auth:sanctum`, named, ordered so static segments precede `{param}`.
10. **Tests** — Feature per endpoint + Unit per Service, SQLite `:memory:`, extend `Tests\TestCase` (spatie roles auto-seeded, Gotenberg auto-faked).

**Worked example — the `crm_tags` directory (shipped 2026-07-01), traced in order:**

| Step | File | Ref |
|---|---|---|
| Migration | `src/database/migrations/2026_07_01_100000_create_crm_tags_table.php` | reversible, indexed |
| Model | `src/app/Domain/Crm/Models/Tag.php` | `protected $table = 'crm_tags'`, `casts()` at :25-31 |
| FormRequest (store) | `src/app/Http/Requests/Crm/StoreTagRequest.php` | `authorize()=true` at :12-15 (gate in controller), `rules()` at :17-26 |
| FormRequest (update) | `src/app/Http/Requests/Crm/UpdateTagRequest.php` | |
| Service | `src/app/Domain/Crm/Services/TagService.php` | `list()/create()/update()/delete()`, all queries here :22-72 |
| Controller | `src/app/Http/Controllers/Crm/Admin/TagController.php` | thin; `$this->authorize('admin-write')` on writes :48,57,67; returns `TagResource` |
| Resource | `src/app/Http/Resources/Crm/TagResource.php` | manual `toArray()` :14-25 |
| Routes | `src/routes/api.php:436-437, 509` | read = any auth user; write = `apiResource` behind admin-write |

This slice is the cleanest small reference for "our CRUD shape." For anything involving
row-level visibility or money, the mature reference is `Sales/DealService` + `DealPolicy` +
`Iam/VisibilityResolver` (as `ARCHITECTURE.md` line 6 already acknowledges for Sales).

---

## 3. ARCHITECTURE.md staleness (proposed patches — NOT applied)

### 3.1 IAM-1 is closed; the doc still describes it as open debt — **highest priority**

**Location:** `ARCHITECTURE.md §3` line 64 (the long "⚠️ Текущее состояние vs цель" block) and §3 line 63; also `CLAUDE.md` "Ключевые решения"/stack section.

**Current text (§3 line 64, paraphrased):**
> "СЕГОДНЯ код авторизует через enum-Gates на колонке `users.role`: таблицы spatie засеяны, но не подключены (permissions висят на guard `web`, а Sanctum их не видит)… это зафиксированный долг IAM-1… `users.role` — переходный двойной источник роли, удаляется после миграции IAM-1."

**Proposed text:**
> "**IAM-1 закрыт (шипнуто).** spatie/laravel-permission работает на guard `sanctum` (default guard = `sanctum`, `config/auth.php`; `sanctum.guard=[]` форсирует Bearer-only). `users.role` **удалён как колонка** — `role` теперь виртуальный accessor поверх единственной spatie-роли с буферизованным mutator + `saved`-хуком (`Domain/Iam/Models/User.php`). Глобальные abilities (`admin-write`, `dedup-scan-all`, `system-reset`, `view-manager-cabinet`) — spatie permissions, авто-регистрируемые как Gate abilities через PermissionRegistrar; `$user->can()`/`can:`-middleware/Policy `hasPermissionTo` резолвятся через матрицу `role_has_permissions`. Row-level видимость — `Iam/Services/VisibilityResolver` (см. §authz)."

**Rationale:** Code (`User.php:22-27,137-198`, `auth.php:18-27`, `sanctum.php:48`,
`AppServiceProvider.php:247-255`) proves the migration shipped. Agents reading the current doc
will re-introduce dead role-column assumptions.

### 3.2 The canonical Domain folder set is incomplete

**Location:** `ARCHITECTURE.md §2` line 52.

**Current text:** `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}`

**Proposed text:** `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies,Events,Listeners}` plus a
follow-on sentence: *"Контексты со специфическими потребностями заводят дополнительные под-папки
по необходимости: `Actions` (Automation), `Exceptions`, `Support`, `Contracts` (интерфейсы/seams),
`Renderers`, `Telegram`, `Extractors`/`Loaders`/`Transformers` (Migration ETL). Канонический
минимум обязателен; расширения — по домену."*

**Rationale:** 11 additional folder kinds exist in reality across most contexts; `Events`/`Listeners`
are used project-wide and should be first-class in the list.

### 3.3 "Vizion is right when a pattern diverges" is now false for built areas

**Location:** `ARCHITECTURE.md` header lines 5 + closing line 159.

**Current text (line 5):** "Если паттерн ниже расходится с тем, как сделано у Vizion, — прав Vizion,
обнови этот файл."

**Proposed text:** "**Vizion — оракул только для ещё-не-построенных примитивов и как tiebreaker
по происхождению.** Для уже построенных областей (слои запроса, авторизация, CRUD-срез, DTO,
тесты) канон — НАШ код (`Sales/DealService`, `Iam/VisibilityResolver`, срез Tags) и этот документ,
а НЕ Vizion. Контроллеры Vizion (`examples/vizion/.../CompanyController.php`) сознательно нарушают
§1 (inline-validate, raw json, роль-строки в контроллере) — их паттерн запросов копировать нельзя."

**Rationale:** Vizion's own controllers violate §1/§3/§7 of our own law. Keeping "Vizion is right"
as a blanket rule contradicts the layered-source-of-truth decision and is actively harmful for
request-handling and authz.

### 3.4 §3 line 63 still lists spatie as "цель/канон" (aspirational) rather than shipped

Fold into 3.1 — change "цель/канон" framing to "действующая модель авторизации".

### 3.5 Stale in-code docstrings to fix alongside (not ARCHITECTURE.md, but doc-drift)

- `VisibilityResolver.php:39-52` — "the mirrored `role` column is the fallback" — the column is dropped; the fallback `$user->role?->value` now reads the spatie-backed accessor, not a column.
- `TestCase.php` `seedRolesAndPermissions()` docblock — "mirrors the `role` column into a spatie role" — there is no column; it's the virtual attribute's buffered write.

---

## 4. Reuse / anti-duplication findings

### 4.1 Confirmed red flag — partial adoption of the LIKE-escape helper

We have a proper safe-LIKE facility: `App\Support\LikeEscape` + `whereLike`/`orWhereLike`/`whereLikeCi`/`orWhereLikeCi`
query-builder macros that emit `LIKE ? ESCAPE ?` (neutralising `%`/`_`/`\`), registered in
`AppServiceProvider.php:352-410`. **But adoption is partial:**

- **Adopters (correct):** `Activity/Services/ActivityService`, `Crm/Services/CompanyService`, `Crm/Services/ContactService`, `Sales/Services/DealService`.
- **Hand-rolled raw LIKE with unescaped user input (should migrate):**
  - `src/app/Domain/Crm/Services/TagService.php:38` — `->where('name', 'like', '%'.$search.'%')` — **the newest feature (2026-07-01) reintroduced the exact anti-pattern the helper exists to prevent.**
  - `src/app/Domain/Catalog/Services/ProductService.php:32-33` — `where('name','like',$term)->orWhere('code','like',$term)`.
  - `src/app/Domain/Onboarding/Services/CourseService.php:36-37` — `where('title','like',$term)->orWhere('description','like',$term)`.

**Action for reuse-checklist:** "Any user-input contains-search → `whereLikeCi()`/`whereLike()`
macro, never `where(col,'like','%'.$x.'%')`." Migrate the three hand-rolled sites.

### 4.2 Non-DDD service holdovers

`src/app/Services/AI/AiRetryService.php` and `src/app/Services/Documents/GotenbergClient.php` sit
outside the DDD tree (Vizion-style flat path). They are widely referenced (TestCase, AppServiceProvider,
SalesPulse). Not a duplication bug, but an organizational inconsistency — candidate to relocate under a
context (or a documented `App\Support`-style shared layer) so "where do cross-cutting services live"
has one answer.

### 4.3 Department-subtree walk is correctly single-sourced (positive finding)

The org-tree BFS lives once in `VisibilityResolver::departmentSubtreeIds()` (`:150-170`) and is
shared by both the query layer (`DealService::scopedQuery`) and the policy layer
(`DealPolicy::inDepartmentSubtree`, `:106-118`). This is the *right* pattern and should be the
template for any future scope logic — do not re-derive department/owner scope inline. `ResolveVisibility`
middleware (`src/app/Http/Middleware/ResolveVisibility.php`) is a convenience carrier only (stamps
`visibility_scope` on the request); enforcement is always in the service via `applyScope()`.

### 4.4 Integrated-library registry (so agents don't add packages)

| Task | Use this (already integrated) | Do NOT add |
|---|---|---|
| AuthN tokens | `laravel/sanctum` (Bearer) | passport, fortify |
| TOTP 2FA | `pragmarx/google2fa` (singleton in AppServiceProvider) | any other 2FA lib |
| Roles/permissions | `spatie/laravel-permission` on `sanctum` guard | custom RBAC, casbin |
| Row-level visibility | `Iam/Services/VisibilityResolver::applyScope()` | inline owner/dept filters |
| Safe LIKE search | `whereLike`/`whereLikeCi` macros + `App\Support\LikeEscape` | raw `'like','%'.$x.'%'` |
| DTOs | plain `final readonly` classes | spatie/laravel-data |
| API responses | hand-written `JsonResource` | spatie/laravel-data, raw arrays |
| Translatable fields | `spatie/laravel-translatable` + jsonb | |
| AI cascades | Prism via `App\Services\AI\AiRetryService` + `config/ai.php` | direct SDK calls |
| DOCX→PDF | `App\Services\Documents\GotenbergClient` (+ PHPWord) | headless-chrome-by-hand |
| Excel export | PhpSpreadsheet | hand-built xlsx |
| Queues | redis (`config/queue.php`) | Horizon |
| Telegram bot | Nutgram (`config/nutgram.php`, SalesPulse second instance) | |
| Error tracking | `sentry/sentry-laravel` | |
| Backups | `spatie/laravel-backup` | |
| Tests | PHPUnit + SQLite `:memory:`, extend `Tests\TestCase` | Pest |

---

## Appendix — evidence index (key refs)

- Vizion fat controller: `examples/vizion/src/app/Http/Controllers/CompanyController.php:29-130`
- Vizion inline-validate auth: `examples/vizion/src/app/Http/Controllers/Auth/AuthController.php:13-32`
- Vizion TestCase (ancestor of ours): `examples/vizion/src/tests/TestCase.php`
- Our thin auth controller: `src/app/Http/Controllers/Auth/AuthController.php:37-98`
- Our thin CRUD controller: `src/app/Http/Controllers/Crm/Admin/TagController.php:32-71`
- IAM-1 closed (User model): `src/app/Domain/Iam/Models/User.php:22-27,47,137-198`
- Sanctum-guard authz: `src/config/auth.php:18-27`, `src/config/sanctum.php:48`, `AppServiceProvider.php:247-255`
- Visibility subsystem: `src/app/Domain/Iam/Services/VisibilityResolver.php`, `src/app/Domain/Sales/Policies/DealPolicy.php:97-118`
- Money kopecks: `src/app/Domain/Sales/Models/Deal.php:103,116`; `2026_06_28_120010_add_payment_fields_to_deals_table.php:27`
- LIKE-escape macros: `src/app/Providers/AppServiceProvider.php:352-410`; helper `src/app/Support/LikeEscape.php`
- Unescaped LIKE outliers: `Crm/Services/TagService.php:38`, `Catalog/Services/ProductService.php:32-33`, `Onboarding/Services/CourseService.php:36-37`
- Route scale: `src/routes/api.php` (356 routes) vs `examples/vizion/src/routes/api.php` (60)
