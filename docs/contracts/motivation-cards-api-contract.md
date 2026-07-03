# Motivation Cards (МК) — Backend API Contract · Phase A

> **Status:** authored by `backend-architect` (spec-author gate) · **Sprint:** Мотивационные карты · **Phase A**
> **Audience:** `sales-backender` (implements), `sales-frontender` (builds against these shapes), `reviewer` (verifies).
> **Sequencing:** this contract exists BEFORE the frontend. Endpoints, response shapes, FormRequest rules,
> Policy gates and DTOs below are the source of truth. Frontend builds against §6 shapes.
>
> **Scope = Phase A only** (plan §8, §11.2): the МК *form* — constructor (leader) + read-only cabinet (manager),
> interim fact on **`won_deals`**, manual plan/fact entry, flexible KPI (§9 ОВ-5), status transitions (§9 ОВ-4),
> auto FX rates (§9 ОВ-1). **Phase B** (auto-commission by payment fact + parametric team-bonus engine) is a
> separate pass with the **Finance** sprint and is only *seam-provisioned* here (§4.3), not implemented.
>
> **Grounding:** stack/patterns → `ARCHITECTURE.md` + `docs/backend-standard.md` + real `src/app/Domain/Sales/*`.
> Business logic → `examples/contracts/` (FastAPI reference — **copy the math, not the code**), specifically
> `apps/api/app/services/salary.py`, `.claude/specs/motivational-card-data-model.md`, `routers/{salary_plans,me}.py`.
> Design → `design-handoff/redesign/motivation-card/SPEC.md`.

---

## 1. Audit verdict — what exists, what to reuse, what is new

### 1.1 What already exists in `src/app/Domain/Sales` (reuse)

| Artifact | File | Verdict |
|---|---|---|
| `SalaryPlan` model | `Models/SalaryPlan.php` | **Reuse + extend.** Has `user_id, period_year, period_month, personal_income_plan_kopecks, personal_income_plan_currency, personal_ftm_plan, team_target_id, commission_rule_id, status`. UNIQUE `(user_id, period_year, period_month)`. Money = integer kopecks. This is the per-manager plan header — МК builds items on top of it. |
| `TeamTarget` model | `Models/TeamTarget.php` | **Reuse + extend.** Has `department_id (nullable), period_year, period_month, target_amount_kopecks, target_currency`. UNIQUE `(department_id, period_year, period_month)`. **Anchor problem — see §1.3.** |
| `CommissionRule` model | `Models/CommissionRule.php` | **Reuse as-is for Phase A.** `rate_pct_times_100` (1000 = 10.00%), `scope, applies_to_first_payment_only, requires_signed_contract, payment_trigger, is_active`. Phase A stores the commission % per-item (see §2.2) — the rule row is optional/linkable. |
| `ManagerKpiService` | `Services/ManagerKpiService.php` | **Reuse the math, add a new service.** Pure helpers `scorePct(fact,plan): ?int` (plan=0 → null, div-by-0 safe), `scoreBadge`, `teamRank`, `teamAvgPct`. DB `personalIncomeFact($userId, KpiFilters, &$warn): int` and `teamKpiBatch(...)` already compute won-deal income FX-normalised to base. **The new `MotivationCardService` DELEGATES to these — never re-implements them.** |
| `ExchangeRateService` | `Domain/Catalog/Services/ExchangeRateService.php` | **Cross-domain FX seam.** `convertAmount(int kopecks, from, to, ?date): ?int` (null = no rate → set `multi_currency_warning`), `getRate`, `fetchAndUpsertFromApi`. МК calls this service — never touches `catalog_exchange_rates`. |
| `KpiFilters` DTO | `Data/KpiFilters.php` | **Reuse.** `forMonth(year, month, ?userId)` builds Carbon boundaries + RU `monthLabel()`. МК services use this to scope a period. |
| Exchange-rate endpoints | routes `api.php:385-397` under `catalog/` prefix | `GET /api/catalog/exchange-rates`, `POST /api/catalog/exchange-rates/refresh`, `GET /api/catalog/exchange-rates/convert`. **SPEC's `GET /api/exchange-rates/latest` does NOT exist** — map B-4 to these (see §6.6). |

### 1.2 What does NOT exist (build new)

- `MotivationCard` + `MotivationCardItem` + `TeamKpiRule` models/migrations (§2, §5).
- `FactSource` interface + `WonDealsFactSource` (§4.2); `PaymentsFactSource` is a Phase-B seam stub only.
- `MotivationCardService` (compute engine + CRUD orchestration, §4.1).
- `motivation.manage` permission (§3.3) — **not in `RolePermissionSeeder` today**.
- Endpoints B-1…B-5 (§6), FormRequests (§7), Resources (§6), Policy (§3).
- `role` + `managed_by` filter on `GET /api/users` (§6.5 — extends existing `UserController`).

### 1.3 ⚠️ Global AI is a PIPELINE, not a department (resolves SPEC ОВ-6)

**Finding:** "MACRO Global" and "MACRO AI Global" are seeded as **pipelines** (`AmoPipelineSeeder`:
"MACRO Global" sort_order 0, "MACRO AI Global" sort_order 1), NOT departments. The real `departments`
table holds "Отдел продаж", "Юридический отдел", "Бухгалтерия", "Сопровождение клиентов" (`DepartmentSeeder`).
`team_targets.department_id` exists but does NOT map to Global / Global AI.

**Decision (resolves ОВ-6):** in МК, the two "teams" (Global / Global AI) are anchored on **`pipeline_id`**, not
`department_id`. The team a manager belongs to for МК purposes = the pipeline their won deals live in.
Consequences the implementer MUST apply:

- `TeamKpiRule` (§2.3) and the МК `team_kpi` item are keyed by **`pipeline_id`** (+ period), not department.
- `MotivationCard.team` (the string enum in the plan) is replaced by a **`pipeline_id` FK** + a denormalised
  `pipeline_name` in the Resource. The `department` field named in the plan §8/§11 is **renamed to
  `pipeline_id`** in the actual schema. (Naming divergence flagged for `reviewer` + frontend.)
- The existing `TeamTarget` (dept-keyed) is left untouched for the S1.8 cabinet; МК introduces its own
  pipeline-keyed target inside `TeamKpiRule.base_pool` + a `team_income_target_kopecks` column, so the two
  subsystems do not collide.
- Team-fact aggregation for the gate = SUM of won-deal income of all managers **whose deals are in that
  pipeline** for the period (via `ManagerKpiService::personalIncomeFact` per member, or a batched pipeline query).

> If product later wants a true org "department" split, that's additive (a nullable `department_id` alongside
> `pipeline_id`). Phase A ships pipeline-keyed only.

---

## 2. Data model (Phase A)

All money = **integer kopecks** (`unsignedBigInteger`/`bigInteger`, cast `'integer'`) — never float/decimal for
money (`docs/backend-standard.md §5`). Percentages/rates = integer basis-points-style (`*_pct` small int, or
`*_pct_times_100` where sub-percent precision is needed).

### 2.1 `MotivationCard` (header, one per user × period)

Table `motivation_cards`. Owning context: `Sales`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users, cascadeOnDelete | the manager the card belongs to |
| `pipeline_id` | FK → pipelines, nullOnDelete, **nullable** | team anchor (Global / Global AI). See §1.3. |
| `period_year` | smallint | |
| `period_month` | smallint | 1..12 |
| `status` | string(20), default `'draft'` | enum `draft | finalized | paid` (§3.4) |
| `base_currency` | string(3), default `'RUB'` | currency the card's totals are expressed in |
| `fact_source` | string(20), default `'won_deals'` | enum `won_deals | payments` (§4.2). Phase A always `won_deals`. |
| `supervisor_user_id` | FK → users, nullOnDelete, nullable | denormalised leader (header shows "Руководитель:") |
| `finalized_at` | timestamptz nullable | set on draft→finalized |
| `finalized_by_user_id` | FK → users, nullOnDelete, nullable | |
| `paid_at` | timestamptz nullable | set on finalized→paid |
| `timestamps` | | |

- **UNIQUE** `(user_id, period_year, period_month)` — one card per manager per month.
- **INDEX** `(pipeline_id, period_year, period_month)` — team rollups + gate aggregation.
- **INDEX** `(user_id, period_year, period_month)` (covered by unique, but keep for the cabinet read).

### 2.2 `MotivationCardItem` (rows: salary components)

Table `motivation_card_items`. One card has 0..N items (one per enabled position toggle; KPI can repeat).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `motivation_card_id` | FK → motivation_cards, cascadeOnDelete | |
| `kind` | string(20) | enum `base_salary | commission | kpi | bonus | team_kpi` (§3.5) |
| `name` | string(160) | display name; for KPI this is the custom KPI title (§9 ОВ-5) |
| `plan_amount_kopecks` | bigint, default 0 | the *indicator* plan (money kinds) — or plan count for `kpi_type=count` (stored as integer, unit in params) |
| `fact_amount_kopecks` | bigint, default 0 | the *indicator* fact — Phase A manual; interim engine may fill from won_deals |
| `salary_plan_kopecks` | bigint, default 0 | ЗП plan for this row (money paid if plan met) |
| `salary_fact_kopecks` | bigint, default 0 | ЗП fact for this row (computed or manual) |
| `currency` | string(3), default base | currency the indicator plan/fact are denominated in (multi-currency, §4.4) |
| `params` | jsonb, nullable | flexible bag — see §2.2.1 |
| `sort` | smallint, default 0 | row order in the card |
| `timestamps` | | |

- **INDEX** `(motivation_card_id, sort)`.
- No UNIQUE on kind — `kpi` may repeat; `base_salary`/`commission`/`team_kpi` are expected once but not DB-enforced (service guards).

#### 2.2.1 `params` jsonb shape (per kind)

Never store money as float inside `params` — money stays in the dedicated `*_kopecks` columns; `params` holds
config/metadata only.

```jsonc
// kind = commission
{ "rate_pct_times_100": 1000, "condition": "personal_deals_first_payment", "payment_note": "immediate",
  "breakdown": [ { "deal_id": 42, "company_name": "Qala Dev", "amount_kopecks": 985750000, "currency": "UZS" } ] }

// kind = kpi  (§9 ОВ-5 — flexible)
{ "kpi_type": "count|amount|manual", "unit": "meetings", "salary_per_completion_kopecks": 500000,
  "manual_done": true }   // manual_done only when kpi_type=manual

// kind = base_salary
{ "payment_note": "next_month" }

// kind = team_kpi   (denormalised snapshot of the rule that applied, for read-only rendering)
{ "team_kpi_rule_id": 7, "split_contribution_pct": 60, "split_equal_pct": 40, "min_threshold_pct": 80,
  "gate_passed": true, "dept_pct": 198,
  "part1_kopecks": 478548900, "part2_kopecks": 257700000 }

// kind = bonus
{ "reason": "..." }
```

### 2.3 `TeamKpiRule` (parametric team-bonus config, per pipeline × period)

Table `team_kpi_rules`. Editable (plan §8 "гибкая/редактируемая"). Keyed on **pipeline** (§1.3).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `pipeline_id` | FK → pipelines, cascadeOnDelete | team anchor |
| `period_year` | smallint | |
| `period_month` | smallint | |
| `team_income_target_kopecks` | bigint, default 0 | the dept/pipeline income plan (gate denominator) |
| `target_currency` | string(3), default `'RUB'` | |
| `base_pool_kopecks` | bigint, default 0 | pool for the base team size |
| `per_extra_member_kopecks` | bigint, default 0 | added per member above `min_members` |
| `min_members` | smallint, default 2 | base team size the pool is quoted for |
| `pool_currency` | string(3), default `'RUB'` | |
| `split_contribution_pct` | smallint, default 60 | Часть 1 — proportional to contribution |
| `split_equal_pct` | smallint, default 40 | Часть 2 — equal; service asserts `contribution+equal=100` |
| `min_threshold_pct` | smallint, default 80 | gate: team must hit ≥ this % of income target |
| `timestamps` | | |

- **UNIQUE** `(pipeline_id, period_year, period_month)`.
- Validation invariant (FormRequest §7): `split_contribution_pct + split_equal_pct = 100`; `min_threshold_pct` 1..100.

---

## 3. Authorization & boundaries

### 3.1 Owning context = `Sales` (decision + rationale)

МК lives in **`app/Domain/Sales`**, NOT a new `Domain/Motivation`. Rationale:
- It sits directly on `SalaryPlan`/`TeamTarget`/`CommissionRule`/`ManagerKpiService` — all already in Sales.
- The fact source is won deals (a Sales concept); team anchor is a Sales `Pipeline`.
- Phase B will add a `PaymentsFactSource` that calls **Finance's** public service — a cross-domain call
  (§3.2), not a reason to move МК out of Sales.

Files land under: `Sales/Models/{MotivationCard,MotivationCardItem,TeamKpiRule}.php`,
`Sales/Enums/{MotivationCardStatus,MotivationCardItemKind,FactSourceKind}.php`,
`Sales/Services/{MotivationCardService,MotivationCardFactory}.php`,
`Sales/Services/FactSource/{FactSource,WonDealsFactSource,PaymentsFactSource}.php`,
`Sales/Policies/MotivationCardPolicy.php`, `Sales/Data/{MotivationCardComputeResult,TeamBonusShares}.php`.

### 3.2 Cross-domain calls (through owning Service only — `docs/backend-standard.md §3`)

| Need | Owner service (inject, never touch table) |
|---|---|
| FX conversion | `Catalog\Services\ExchangeRateService::convertAmount()` |
| Won-deal income fact / team batch | `Sales\Services\ManagerKpiService::personalIncomeFact()` / `teamKpiBatch()` (same context) |
| Manager list / subordinates (`managed_by=me`) | `Iam` — extend `UserController` + query `users.manager_id` (Iam-owned) |
| Payment fact (Phase B only) | `Finance` public service — **not called in Phase A**; seam only |

`MotivationCardService` orchestrates via injected services exactly like `DealService` does — never runs a
foreign-table query.

### 3.3 New permission: `motivation.manage`

Add to `RolePermissionSeeder`:
- Register `motivation.manage` on the `sanctum` guard (like every other permission, §180-193 of the seeder).
- **Grant to:** `admin`, `director`. (These are the constructor/plan authors — plan §B.)
- Auto-registered as a Gate ability by spatie's `PermissionRegistrar` → resolves via `$user->can('motivation.manage')`,
  `can:motivation.manage` middleware, and Policy bodies. **No inline role checks** (`docs/backend-standard.md §4`).

### 3.4 Status-transition authorization (§9 ОВ-4)

Transitions `draft → finalized → paid` are performed by **`accountant | director | admin`** (a *different,
broader* set than `motivation.manage`, which is admin/director only — accountant can move status but cannot
edit the plan). Implementation:

- Gate the transition endpoints with a check for role-set `['accountant','director','admin']` **expressed as a
  Policy ability** on `MotivationCardPolicy::transitionStatus()`, NOT an inline `if ($user->role===...)`.
- To keep it permission-based, EITHER grant an additional `motivation.status` permission to those three roles,
  OR resolve the three-role set inside the Policy via `$user->can()` composites. **Recommended:** add a second
  permission `motivation.status` granted to `accountant, director, admin`; the Policy checks
  `$user->can('motivation.status')`. This keeps authz in the spatie matrix and avoids role-string checks.
- Status machine guard lives in the Service (`assertCanTransition($from, $to)`), mirroring `DealService`
  transition guards (`docs/backend-standard.md §3`). Allowed edges: `draft→finalized`, `finalized→paid`,
  and `finalized→draft` (un-finalize, admin only — optional; default deny for Phase A unless product asks).
  A finalized/paid card is read-only to the constructor's write endpoints (guard in `MotivationCardService`).

### 3.5 Read authorization (cabinet)

`GET /api/motivation/cards/me`:
- `manager` — own card only (403 on any other `user_id`), reusing the exact pattern of
  `ManagerKpiService::resolveTargetUser()` + `view-manager-cabinet`.
- `director` — own + subordinates (`managed_by` = users with `manager_id = director.id`), via
  `manager-cabinet.view-all` OR the subordinate check.
- `admin` — any user.
- The Policy (`MotivationCardPolicy::view`) reuses `manager-cabinet.view-all` for the privileged read so read
  scope never diverges from the S1.8 cabinet (§4 visibility principle).

### 3.6 Enums

```php
enum MotivationCardStatus: string { case Draft='draft'; case Finalized='finalized'; case Paid='paid'; }
enum MotivationCardItemKind: string { case BaseSalary='base_salary'; case Commission='commission';
    case Kpi='kpi'; case Bonus='bonus'; case TeamKpi='team_kpi'; }
enum FactSourceKind: string { case WonDeals='won_deals'; case Payments='payments'; }
enum KpiType: string { case Count='count'; case Amount='amount'; case Manual='manual'; }  // item.params.kpi_type
```

---

## 4. Compute engine (signatures, not implementation)

The engine is **pure where possible** (`docs/backend-standard.md §5` invariant: pure helpers are unit-testable
without DB), delegating DB/FX to injected services. Math is copied from `examples/contracts/.../salary.py`
(the reference), expressed in integer kopecks.

### 4.1 `MotivationCardService` (public surface)

```php
final class MotivationCardService
{
    public function __construct(
        private readonly ManagerKpiService $kpiService,          // won-deal income + score pure-helpers
        private readonly ExchangeRateService $exchangeRateService,
        private readonly FactSourceResolver $factSourceResolver, // picks WonDeals vs Payments by card.fact_source
    ) {}

    // ---- CRUD / constructor (B-2, B-3) ----
    /** Read the plan (card + items + team rule) for the constructor. Null if none. */
    public function findForConstructor(int $userId, int $year, int $month): ?MotivationCard;

    /** Create-or-update the whole card (header + items + team rule) atomically (DB::transaction). Idempotent
     *  by (user_id, year, month). Rejects writes to a finalized/paid card. Returns the persisted card. */
    public function upsertPlan(UpsertPlanData $data, User $actor): MotivationCard;

    /** Copy prior-month plan structure into (year, month) as a draft. Returns null if no prior card. */
    public function copyFromPreviousMonth(int $userId, int $year, int $month): ?MotivationCard;

    // ---- Status machine (B-3 transitions) ----
    public function assertCanTransition(MotivationCardStatus $from, MotivationCardStatus $to): void; // guard
    public function finalize(MotivationCard $card, User $actor): MotivationCard; // draft→finalized + stamp
    public function markPaid(MotivationCard $card, User $actor): MotivationCard;  // finalized→paid + stamp

    // ---- Cabinet read + compute (B-1) ----
    /** Full read payload for the manager cabinet: card + items with computed facts + team-bonus forecast +
     *  dept plan/fact/pct + KPI indicators + rates snapshot. Recomputes interim facts on read (Phase A). */
    public function buildCabinetPayload(int $userId, int $year, int $month, User $viewer): array;
}
```

### 4.2 FactSource abstraction (§4.3 seam for Phase B)

```php
interface FactSource
{
    /** Personal income fact for a manager in the period, in base currency kopecks. */
    public function personalIncome(int $userId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): int;

    /** Per-member contribution map for a team (pipeline) in the period, base kopecks, for the 60/40 split. */
    public function teamContributions(array $userIds, int $pipelineId, KpiFilters $period, string $baseCurrency, bool &$fxWarning): array;

    public function kind(): FactSourceKind;
}

// Phase A — the only live implementation. Delegates to ManagerKpiService (won deals, FX-normalised).
final class WonDealsFactSource implements FactSource { /* wraps ManagerKpiService::personalIncomeFact / teamKpiBatch */ }

// Phase B — seam stub. Throws / returns 0 until Finance ships; wired by fact_source='payments'.
final class PaymentsFactSource implements FactSource { /* calls Finance public service — NOT in Phase A */ }

final class FactSourceResolver { public function for(FactSourceKind $kind): FactSource; }
```

The card's `fact_source` column selects the implementation. Phase A cards are always `won_deals`; the resolver
already knows how to return `PaymentsFactSource` once Finance exists — no schema change needed to flip.

### 4.3 Pure math helpers (copy from `salary.py`, integer kopecks)

```php
// commission = round(personalFactKopecks * rate_pct_times_100 / 10000)
public function commissionKopecks(int $personalFactKopecks, int $ratePctTimes100): int;

// pool = base_pool + max(0, n_members - min_members) * per_extra
public function teamBonusPoolKopecks(int $basePool, int $nMembers, int $perExtra, int $minMembers = 2): int;

/** Returns [part1_kopecks (proportional), part2_kopecks (equal)].
 *  part1 = poolProportional * userContribution / teamTotal  (teamTotal==0 → 0, #DIV/0 guard)
 *  part2 = poolEqual / nMembers                              (nMembers==0 → 0)
 *  poolProportional = pool * split_contribution_pct / 100 ; poolEqual = pool * split_equal_pct / 100 */
public function teamBonusShares(int $pool, int $splitContribPct, int $splitEqualPct,
    int $nMembers, int $userContribution, int $teamTotal): array; // -> TeamBonusShares DTO

// gate: teamFact/teamTarget*100 >= min_threshold_pct. teamTarget==0 → gate FAILS (not #DIV/0 → not "passed").
public function gatePassed(int $teamFactKopecks, int $teamTargetKopecks, int $minThresholdPct): bool;
```

**Rounding:** round-half-up, done in integer arithmetic (`intdiv` with +half, or reuse the bcmath pattern in
`ExchangeRateService::convertAmount`). **#DIV/0 → 0** everywhere a denominator can be zero (plan §8 A2, §11.6).

### 4.4 Multi-currency

- Each `MotivationCardItem` carries its own `currency`. Indicator plan/fact are stored in that currency.
- The card total and the team-bonus forecast are expressed in `card.base_currency`.
- Conversion is per-item via `ExchangeRateService::convertAmount(kopecks, item.currency, base_currency, ratesDate)`.
- `ratesDate` = 1st of the card's month (matches `salary.py` `rates_date = date(year, month, 1)`).
- If any rate is missing → `convertAmount` returns null → set `multi_currency_warning=true` in the payload and
  fall back to the raw amount (same defensive behaviour as `ManagerKpiService::normalisedPlanKopecks`).
- The cabinet payload includes a `rates` block (source + date + used pairs) for the read-only rates footer.

### 4.5 Idempotency & live forecast

- `upsertPlan` and the interim recompute are idempotent by `(user_id, year, month)` — re-running yields the
  same rows (upsert, not insert-duplicate).
- **Team-bonus forecast is computed on read** from data already in the payload (dept plan/fact + this manager's
  contribution) — the frontend does NOT need a separate request (SPEC §Прогноз бонуса, ОВ-2: FE polls the same
  `cards/me` endpoint every 30s; no dedicated forecast endpoint).

---

## 5. Migrations & gap analysis

### 5.1 Migrations (reversible up/down, pgsql-verified before commit — `docs/backend-standard.md §5`)

| Migration (name pattern `YYYY_MM_DD_HHMMSS_<verb>_<entity>`) | Purpose |
|---|---|
| `create_motivation_cards_table` | §2.1 — money bigint kopecks, FKs constrained, unique + indexes |
| `create_motivation_card_items_table` | §2.2 — `params` jsonb, index `(motivation_card_id, sort)` |
| `create_team_kpi_rules_table` | §2.3 — unique `(pipeline_id, year, month)` |

`down()` drops each table. `jsonb` for `params`. FK `->constrained()->cascadeOnDelete()` / `nullOnDelete()` per
column above. Both `migrate` + `migrate:rollback` must pass on pgsql; sqlite test suite must survive (guard any
raw PG DDL with `DB::getDriverName()==='pgsql'` — not needed here, all standard Blueprint).

### 5.2 Seeder change

`RolePermissionSeeder`: add `motivation.manage` (admin, director) and `motivation.status` (accountant, director,
admin) to the permission list + role grants (§3.3, §3.4). Idempotent `firstOrCreate` + `syncPermissions` — the
seeder already re-syncs, so re-running is safe.

### 5.3 Effort — S / M / L

| Work | Size | Notes |
|---|---|---|
| 3 models + 3 migrations + 3 enums + 2 DTOs | **S** | mechanical, mirrors existing Sales models |
| 2 permissions in seeder | **S** | 4 lines + grants |
| `FactSource` interface + `WonDealsFactSource` (wraps existing `ManagerKpiService`) + resolver | **S–M** | reuses shipped won-deal math |
| `PaymentsFactSource` seam stub | **S** | throws until Finance |
| `MotivationCardService` CRUD (upsert atomic, copy-prev, status machine) | **M** | transaction + guards |
| `MotivationCardService` compute (commission, 60/40 shares, gate, multi-currency, forecast) | **M–L** | the non-trivial part; math copied from `salary.py`; heavy test coverage on reference numbers |
| Controllers + FormRequests + Resources (B-1…B-3) | **M** | thin controllers, hand-written Resources |
| `GET /api/users` `role`+`managed_by` extension (B-5) | **S** | extend `UserIndexRequest` + `UserController` query |
| B-4 rates: reuse `catalog/exchange-rates` (no new endpoint) | **S** | frontend points at existing catalog routes |
| Tests: Unit (pure math on эталон numbers, #DIV/0→0, gate 80%, 60/40) + Feature per endpoint | **M–L** | эталон = `МК Георгий Некрасов` xlsx / SPEC ASCII numbers |

**Total: solid M (multi-day).** The compute engine + its test matrix is the weight; everything else is mechanical
reuse of shipped Sales patterns.

---

## 6. Endpoints (B-1…B-5) — routes, shapes, Resources

All under `/api`, `auth:sanctum`. New route group `Route::prefix('motivation')` (cabinet, read) +
`Route::prefix('admin/motivation')->middleware('can:motivation.manage')` (constructor, write). Controllers live in
`app/Http/Controllers/Sales/Motivation/`. **All responses via hand-written `JsonResource` — never raw arrays**
(`docs/backend-standard.md §1`).

### 6.1 B-1 · `GET /api/motivation/cards/me` — manager cabinet card (read-only)

Query: `?year=2026&month=4&user_id=<optional, director/admin only>`.
Controller: thin → `MotivationCardService::buildCabinetPayload()` → `MotivationCardResource` (`$wrap=null`, root-level
like `KpiResource`).

```jsonc
{
  "meta": {
    "user": { "id": 12, "full_name": "Илья Рогов", "avatar_path": null },
    "supervisor": { "id": 3, "full_name": "Богдан Ядыкин" },
    "pipeline": { "id": 1, "name": "MACRO Global" },
    "period": { "year": 2026, "month": 4, "label": "Апрель 2026" },
    "status": "draft",                       // draft|finalized|paid
    "base_currency": "UZS",
    "fact_source": "won_deals",              // Phase A badge "расчёт по выигранным, не по оплате"
    "multi_currency_warning": false,
    "has_card": true
  },
  "dept_plan": {                             // team (pipeline) income target vs fact — gate basis
    "target_kopecks": 80000000, "target_currency": "RUB",
    "fact_kopecks": 158636500, "pct": 198, "badge": "success"
  },
  "items": [
    { "kind": "base_salary", "name": "Оклад", "sort": 0,
      "plan_amount_kopecks": 1700000000, "fact_amount_kopecks": 1700000000, "currency": "UZS",
      "salary_plan_kopecks": 1700000000, "salary_fact_kopecks": 1700000000, "pct": 100, "badge": "success",
      "params": { "payment_note": "next_month" } },
    { "kind": "commission", "name": "Комиссия", "sort": 1,
      "plan_amount_kopecks": 60000000, "fact_amount_kopecks": 98213500, "currency": "RUB",
      "salary_plan_kopecks": 908100000, "salary_fact_kopecks": 1567450000, "pct": 164, "badge": "success",
      "params": { "rate_pct_times_100": 1000, "payment_note": "immediate",
                  "breakdown": [ { "deal_id": 42, "company_name": "Qala Dev", "amount_kopecks": 985750000, "currency": "UZS" } ] } },
    { "kind": "kpi", "name": "FTM встречи", "sort": 2,
      "plan_amount_kopecks": 0, "fact_amount_kopecks": 0, "currency": "UZS",
      "salary_plan_kopecks": 0, "salary_fact_kopecks": 0, "pct": 120, "badge": "success",
      "params": { "kpi_type": "count", "unit": "meetings", "plan_count": 10, "fact_count": 12,
                  "salary_per_completion_kopecks": 0 } },
    { "kind": "team_kpi", "name": "Командный бонус", "sort": 3,
      "plan_amount_kopecks": 40000000, "fact_amount_kopecks": 0, "currency": "KZT",
      "salary_plan_kopecks": 40000000, "salary_fact_kopecks": 736248900, "pct": 248, "badge": "success",
      "params": { "split_contribution_pct": 60, "split_equal_pct": 40, "min_threshold_pct": 80,
                  "gate_passed": true, "part1_kopecks": 478548900, "part2_kopecks": 257700000 } }
  ],
  "total": { "salary_plan_kopecks": 2648100000, "salary_fact_kopecks": 4003698900, "currency": "UZS" },
  "team_bonus_forecast": {                   // live; FE re-renders / polls 30s (ОВ-2)
    "gate_passed": true, "dept_pct": 198, "threshold_pct": 80,
    "part1_kopecks": 478548900, "part2_kopecks": 257700000, "total_kopecks": 736248900, "currency": "UZS"
  },
  "rates": { "date": "2026-04-01", "source": "api",
             "pairs": [ { "from": "RUB", "to": "UZS", "rate": "151.000000" }, { "from": "KZT", "to": "UZS", "rate": "26.000000" } ] }
}
```

Notes for frontend: `pct` may be `null` (no plan → render «—», badge `"none"`); `kpi_type=manual` items carry
`params.manual_done` (bool) instead of a meaningful `pct` — render a Выполнено/Не выполнено badge. Money is
integer kopecks — format on FE (`docs/backend-standard.md §5`). If `has_card=false` → 200 with `meta.has_card:false`
and empty `items` (not 404), so the cabinet can show the "card not created yet" empty state.

### 6.2 B-2 · `GET /api/admin/motivation/plans/{userId}/{year}/{month}` — read plan for constructor

`can:motivation.manage`. Returns the editable plan (card header + items + team rule) or `{ "data": null }` if none.
Resource `MotivationPlanResource`. Shape mirrors §6.1 items but *editable* fields only (no computed `salary_fact`
for empty facts; includes `team_kpi_rule` block with `base_pool_kopecks, per_extra_member_kopecks, min_members,
split_*_pct, min_threshold_pct, team_income_target_kopecks, pipeline_id`).

### 6.3 B-3 · `POST /api/admin/motivation/plans` — create/update plan

`can:motivation.manage`. Idempotent upsert by `(user_id, year, month)`. FormRequest §7.1. Body:

```jsonc
{
  "user_id": 12, "year": 2026, "month": 4, "pipeline_id": 1, "base_currency": "UZS", "supervisor_user_id": 3,
  "items": [
    { "kind": "base_salary", "name": "Оклад", "plan_amount_kopecks": 1700000000, "currency": "UZS",
      "params": { "payment_note": "next_month" } },
    { "kind": "commission", "name": "Комиссия", "plan_amount_kopecks": 60000000, "currency": "RUB",
      "params": { "rate_pct_times_100": 1000 } },
    { "kind": "kpi", "name": "FTM встречи", "currency": "UZS",
      "params": { "kpi_type": "count", "unit": "meetings", "plan_count": 10, "salary_per_completion_kopecks": 0 } }
  ],
  "team_kpi_rule": {
    "pipeline_id": 1, "team_income_target_kopecks": 80000000, "target_currency": "RUB",
    "base_pool_kopecks": 50000000, "per_extra_member_kopecks": 10000000, "min_members": 2, "pool_currency": "KZT",
    "split_contribution_pct": 60, "split_equal_pct": 40, "min_threshold_pct": 80
  }
}
```

Response: `201` (created) / `200` (updated) → `MotivationPlanResource` (same as B-2). `409` if the card is
finalized/paid (write blocked). `422` on validation. Manual plan/fact entry: for Phase A, `fact_amount_kopecks`
may be supplied manually per item; if omitted the interim engine fills won-deal facts on read.

**Status-transition sub-routes** (separate, `can:motivation.status` — accountant/director/admin, §3.4):
- `POST /api/admin/motivation/plans/{card}/finalize` → draft→finalized, stamps `finalized_at/by`. `409` if not draft.
- `POST /api/admin/motivation/plans/{card}/mark-paid` → finalized→paid, stamps `paid_at`. `409` if not finalized.

**Copy previous month:**
- `POST /api/admin/motivation/plans/copy-previous` body `{ user_id, year, month }` → clones prior month structure
  as draft. `200` with `MotivationPlanResource`, or `{ "data": null }` if no prior card (FE shows info toast).

### 6.4 B-4 · Rates — **reuse existing `catalog/exchange-rates` (no new endpoint)**

SPEC's `GET /api/exchange-rates/latest` does not exist. Frontend uses the shipped Catalog routes:
- Latest rates list: `GET /api/catalog/exchange-rates?to_code=<base>` (paginated, `ExchangeRateResource`).
- On-demand refresh: `POST /api/catalog/exchange-rates/refresh` (202, `catalog.manage`).
- Ad-hoc convert: `GET /api/catalog/exchange-rates/convert?from=&to=&amount=&date=`.

The cabinet payload (§6.1) already embeds a `rates` block, so the read-only rates footer needs no extra call.
Auto-rates (ОВ-1) = the daily `UpdateExchangeRatesJob` + this refresh button; **no manual rate inputs in the
constructor** (per ОВ-1). **Reuse-gate: do NOT add a `motivation`-specific rates endpoint.**

### 6.5 B-5 · `GET /api/users?role=manager&managed_by=me` — manager AutoComplete

Extend the existing `GET /api/users` (`UserController`, Iam) — do NOT create a parallel endpoint. Add to
`UserIndexRequest`: `role` (nullable, in the 6 role names), `managed_by` (nullable, `me` or a user id). Query:
- `role=manager` → filter to users holding the spatie role (`->role('manager')` scope, already used in
  `ManagerKpiService::resolveTeamMemberIds`).
- `managed_by=me` → `where('manager_id', $request->user()->id)` (director sees only subordinates, §3.5 / ОВ-3).
- `managed_by=<id>` → admin can scope to any leader's subordinates.
- Response: existing `UserOptionResource` (flat `data[]`, no pagination) — unchanged shape.

Authorization stays as-is (any authed user may read the directory); `managed_by=me` is self-scoping so no new gate.

### 6.6 Route registration summary

```
// cabinet (read)
Route::prefix('motivation')->name('motivation.')->group(function () {
    Route::get('cards/me', [MotivationCardController::class, 'me'])->name('cards.me');
});
// constructor (write) — plan authoring
Route::prefix('admin/motivation')->name('admin.motivation.')->middleware('can:motivation.manage')->group(function () {
    Route::get('plans/{userId}/{year}/{month}', [MotivationPlanController::class, 'show'])->name('plans.show');
    Route::post('plans', [MotivationPlanController::class, 'store'])->name('plans.store');
    Route::post('plans/copy-previous', [MotivationPlanController::class, 'copyPrevious'])->name('plans.copy-previous');
    // status transitions — Policy gates to motivation.status (accountant/director/admin)
    Route::post('plans/{card}/finalize', [MotivationPlanController::class, 'finalize'])->name('plans.finalize');
    Route::post('plans/{card}/mark-paid', [MotivationPlanController::class, 'markPaid'])->name('plans.mark-paid');
});
```

---

## 7. FormRequest rules

### 7.1 `StoreMotivationPlanRequest` (B-3)

- `authorize()`: `true` (gate is `can:motivation.manage` on the route + Policy on write).
- Rules:
  - `user_id` required, exists:users,id
  - `year` required, integer, 2020..2100
  - `month` required, integer, 1..12
  - `pipeline_id` nullable, exists:pipelines,id
  - `base_currency` required, string, size:3
  - `supervisor_user_id` nullable, exists:users,id
  - `items` required, array, min:1
  - `items.*.kind` required, in the 5 kind values
  - `items.*.name` required, string, max:160
  - `items.*.plan_amount_kopecks` nullable, integer, min:0
  - `items.*.fact_amount_kopecks` nullable, integer, min:0 (manual entry, Phase A)
  - `items.*.currency` required, string, size:3
  - `items.*.params` nullable, array
  - `items.*.params.rate_pct_times_100` (when kind=commission) integer, 1..10000
  - `items.*.params.kpi_type` (when kind=kpi) in count|amount|manual
  - `team_kpi_rule` nullable, array
  - `team_kpi_rule.pipeline_id` required_with:team_kpi_rule, exists:pipelines,id
  - `team_kpi_rule.min_threshold_pct` integer, 1..100
  - `team_kpi_rule.split_contribution_pct` integer, 0..100
  - `team_kpi_rule.split_equal_pct` integer, 0..100
  - `team_kpi_rule.*_kopecks` integer, min:0
  - `withValidator`: assert `split_contribution_pct + split_equal_pct === 100`.
- `messages()`: RU custom copy per SPEC §Валидация.

### 7.2 `UserIndexRequest` (extend, B-5)

Add: `role` nullable, string, `Rule::in(Role::values())`; `managed_by` nullable, string (`me` or numeric id).

### 7.3 Status transitions

No body → tiny `FinalizeMotivationCardRequest` / `MarkPaidRequest` with `authorize()` delegating to
`$this->user()->can('motivation.status')` (or a bare FormRequest + Policy on the controller). The
status-machine legality (`draft→finalized` etc.) is enforced in the Service, returning `409` on illegal edges.

---

## 8. Tests (PHPUnit + SQLite :memory:, extend `Tests\TestCase`)

`docs/backend-standard.md §5`: Feature per endpoint, Unit per Service; triple isolation; the base `TestCase`
auto-seeds the spatie role/permission matrix — so `motivation.manage`/`motivation.status` must be in
`RolePermissionSeeder` for tests to grant them.

**Unit (pure math — эталон numbers from `МК Георгий Некрасов` xlsx / SPEC ASCII macros):**
- `commissionKopecks`: 10% of 250 000 000 kop → 25 000 000 kop; 164% case from §6.1.
- `teamBonusPoolKopecks`: base 50 000 000 on 2 members = 50 000 000; 3 members + 10 000 000 extra = 60 000 000.
- `teamBonusShares`: 60/40 split, part1 proportional to contribution, part2 equal; **teamTotal=0 → (0,0)**;
  **nMembers=0 → (0,0)** (#DIV/0 → 0).
- `gatePassed`: 198% ≥ 80% → true; 54% → false; **teamTarget=0 → false** (not "passed").
- `scorePct` reuse: plan=0 → null; fact<0 → 0 (already tested for `ManagerKpiService`, assert delegation).
- Multi-currency: item in RUB converted to UZS base via faked `ExchangeRateService`; missing rate → warning flag.

**Feature (one per endpoint):**
- `GET /api/motivation/cards/me`: manager sees own; manager 403 on other `user_id`; director sees subordinate;
  admin sees any; `has_card=false` empty-state 200; status/fact_source badges present.
- `POST /api/admin/motivation/plans`: manager 403 (no `motivation.manage`); admin/director 201 create → 200 update
  (idempotent); 409 on finalized card; 422 on split≠100 / bad kind.
- `finalize`/`mark-paid`: accountant/director/admin can; manager 403; illegal edge → 409.
- `copy-previous`: clones prior month; null when no prior.
- `GET /api/users?role=manager&managed_by=me`: director sees only own subordinates; role filter works.

---

## 9. Open questions — with defaults applied

All defaults chosen so implementation can proceed without blocking; flag to `reviewer`/PM if any is wrong.

| # | Question | **Default applied** |
|---|---|---|
| Q1 | Global / Global AI — department or pipeline? | **PIPELINE** (§1.3). МК team scope keyed on `pipeline_id`. Plan's `department` field renamed `pipeline_id`. |
| Q2 | Where do status transitions live in authz? | Second permission **`motivation.status`** (accountant/director/admin), Policy-checked — not inline roles (§3.4). |
| Q3 | Team-fact denominator for the gate — personal-plan or team income target? | **Team income target** (`team_kpi_rules.team_income_target_kopecks`), per PDF "80% от плана" = department/pipeline plan (§4.3, salary.py). |
| Q4 | KPI money (`salary_per_completion_kopecks`) required? | **Optional** — flexible KPI (ОВ-5); `manual`/`count` KPIs may pay 0 (pure indicator) or a fixed sum. Stored in `params`. |
| Q5 | `rates_date` for conversion? | **1st of the card's month** (matches salary.py `date(year,month,1)`). Finalization does NOT re-snapshot in Phase A (interim recompute on read); a rate snapshot column is deferred to Phase B. |
| Q6 | New rates endpoint `GET /exchange-rates/latest`? | **No** — reuse `catalog/exchange-rates*` (§6.4). Reuse-gate: no motivation-specific rates route. |
| Q7 | Un-finalize (finalized→draft)? | **Deny by default** in Phase A (only draft→finalized→paid). Add later if product asks. |
| Q8 | Does a finalized card freeze its facts (snapshot)? | **Phase A: no hard freeze** — facts recompute on read; write endpoints are blocked once finalized (409). Immutable snapshot = Phase B (Finance) concern. |

---

## 10. Handoff notes for `sales-backender`

1. Build order: enums → 3 migrations → 3 models + factories → `RolePermissionSeeder` (2 permissions) →
   `FactSource` interface + `WonDealsFactSource` + resolver → `MotivationCardService` (CRUD then compute) →
   Controllers/FormRequests/Resources → extend `UserController` (B-5) → tests.
2. **Reuse, do not re-implement:** won-deal income + `scorePct`/`scoreBadge` come from `ManagerKpiService`;
   FX from `ExchangeRateService`; safe-LIKE via `whereLikeCi` macros if you add search; row-visibility via the
   cabinet pattern already in `resolveTargetUser`. (`docs/backend-standard.md §6` reuse checklist.)
3. **Money = integer kopecks; percentages = small int; params jsonb holds config only, never money-as-float.**
4. **No inline role checks** — `motivation.manage` / `motivation.status` permissions + Policy only.
5. Verify `migrate` + `migrate:rollback` on pgsql; `php artisan test` green; `pint` clean before handing back.
6. **Frontend depends on §6 response shapes** — if any field name changes during implementation, flag main so
   the frontend contract and PLAN sync via `reviewer` (public-API change).
