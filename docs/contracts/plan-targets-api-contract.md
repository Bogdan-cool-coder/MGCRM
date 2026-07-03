# Plan Targets (Планы и отчёты / Sales Analytics) — Backend API Contract · Ф0

> **Status:** authored by `backend-architect` (spec-author gate) · **Sprint:** Планы и отчёты (Sales Analytics) · **Phase Ф0**
> **Audience:** `sales-backender` (implements Ф1+), `sales-frontender` (builds against these shapes), `reviewer` (verifies), `designer` (Ф0 dashboard-hub TЗ parallel).
> **Sequencing:** this contract exists BEFORE the frontend. Table `plan_targets`, endpoints, response shapes, FormRequest rules,
> permission `plans.manage` and the R1–R6 report shapes below are the source of truth. Frontend builds against §6/§7 shapes.
>
> **Scope of Ф0 = spec only.** No code, no migrations. This document defines the model + endpoints + report shapes;
> Ф1 (sales-backender) builds `plan_targets` migration + `PlanTargetService` + matrix/bulk-upsert for **new_income end-to-end** only.
> Ф2–Ф5 add the report aggregators; Ф6 wires МК. §9 fixes the phase split precisely.
>
> **Grounding:** stack/patterns → `ARCHITECTURE.md` + `docs/backend-standard.md` + real `src/app/Domain/Sales/*`
> (mature refs: `SalesDashboardService` — single aggregator + `VisibilityResolver`; `ManagerKpiService` — won-deal fact + FX;
> `DealAudit` — append-only change log). Business logic → SpaceCRM analysis `docs/audit/SpaceCRM-reports-analysis-2026-07.md`
> (copy the mechanics, not the UI). МК seam → `docs/contracts/motivation-cards-api-contract.md` (kopecks / multi-currency / FactSource / permissions).

---

## 1. Audit verdict — what exists, what to reuse, what is new

### 1.1 What already exists in `src/app/Domain/Sales` + siblings (reuse — do NOT re-implement)

| Artifact | File | Verdict for this sprint |
|---|---|---|
| `ManagerKpiService::personalIncomeFact` / `teamKpiBatch` | `Sales/Services/ManagerKpiService.php` | **Reuse for interim money-fact.** Won-deal income, FX-normalised, single GROUP BY. R6/R2/R4-money facts DELEGATE here — never re-query won deals. Interim fact = `won_deals` (identical FactSource seam as МК). |
| `ManagerKpiService::scorePct` / `scoreBadge` | same | **Reuse the pure helpers.** plan=0 → null (render «—», badge `none`); ≥100 success / ≥80 warning / else danger. Every план/факт % in R1–R6 uses these — the badge semantics stay identical to the cabinet + МК. |
| `SalesDashboardService::funnelMetrics` / `forecastData` | `Sales/Services/SalesDashboardService.php` | **Reuse for R5 stage-conversions + двойной прогноз (R6-adjacent).** `funnelMetrics` already computes per-stage transition % from `deal_stage_history`-fed stage counts; `forecastData` gives the weighted forecast (our "smart" model beside the additive one). |
| `SalesDashboardService::baseQuery` / `effectiveDateExpr` | same | **Reuse the effective-date recognition + visibility-scoped base query.** R1/R2/R6 period recognition (won/lost → `COALESCE(closed_at, signed_at, paid_at, stage_changed_at)`; active → `stage_changed_at`) mirrors this EXACTLY so an aggregate and the dashboard never disagree on which month a deal lands in. |
| `VisibilityResolver` (`resolve` / `applyScope` / `departmentSubtreeIds`) | `Iam/Services/VisibilityResolver.php` | **Reuse for all report READ-scope.** manager → Department (M9), director → All, accountant/cfo → Own. Report aggregators call `applyScope($q, $user, ['owner_user_id'], 'department_id')` OR the dashboard's inline match — one scope source, no drift. |
| `ExchangeRateService::convertAmount` | `Catalog/Services/ExchangeRateService.php` | **Cross-domain FX seam.** `convertAmount(kopecks, from, to, ?date): ?int` (null → set `multi_currency_warning`, fall back to raw). Multi-currency plan/fact aggregation goes through this; `date` = 1st of the target month (§4). Never touch `catalog_exchange_rates`. |
| `KpiFilters` DTO | `Sales/Data/KpiFilters.php` | **Reuse.** `forMonth(year, month, ?userId)` → Carbon boundaries + RU `monthLabel()`. Planning services scope a period with it. |
| `Deal` fact/plan date columns | `Sales/Models/Deal.php` | **Reuse — all present.** `expected_payment_date`, `expected_sign_date`, `signed_at`, `paid_at`, `paid_amount`, `payment_currency`, `amount` (kopecks). R1 «дожим» = open deals with `expected_payment_date < today && paid_at IS NULL`. |
| `DealProduct` → `Product` → `ProductGroup` | `Sales/Models/DealProduct.php`, `Catalog/Models/{Product,ProductGroup}.php` | **Reuse — this IS the продуктовая линейка axis.** `deal_products.product_id → catalog_products.group_id → catalog_product_groups` (MacroSales CRM / MACRO AI / MacroCatalog / MacroBroker / …). R6 `product_income` scope = `catalog_product_groups.id`. **No new "product line" table.** |
| `DealStageHistory` (append-only) | `Sales/Models/DealStageHistory.php` | **Reuse for R5 honest stage-conversions.** `from_stage_id → to_stage_id`, `created_at` — our advantage over SpaceCRM's task-only conversions. |
| `Activity` (`kind` / `completed_at` / `responsible_id`) | `Activity/Models/Activity.php` | **Reuse for R4 task-matrix fact.** `ActivityType` enum values: `call | meeting | task | note | follow_up | presentation`. Fact = COUNT of completed activities grouped by `kind × responsible_id × month`. FTM via `Activity::scopeFtmCounted` if a KPI targets FTM specifically. |
| `DealAudit` | `Sales/Models/DealAudit.php` | **Pattern reference for the plan-change audit** (§2.2). One row per change, append-only, `UPDATED_AT = null`, `old_value`/`new_value` JSON scalars. `plan_target_audits` follows this shape verbatim. |
| `SalesDashboardService::buildXlsx` (PhpSpreadsheet A1-notation) | same | **Reuse pattern for Ф5 Excel export** of all reports (SpaceCRM only exports PNG — our козырь). |
| `catalog/exchange-rates*` endpoints + daily `UpdateExchangeRatesJob` | routes + Catalog | **Reuse — no new rates endpoint** (reuse-gate, same as МК §6.4). Auto-rates already run daily; refresh button reuses `POST /api/catalog/exchange-rates/refresh`. |

### 1.2 What does NOT exist (build new)

- `plan_targets` table + `PlanTarget` model + `plan_target_audits` table + `PlanTargetAudit` model (§2).
- `PlanMetric` / `PlanScopeType` / `PlanLayer` enums (§3).
- `PlanTargetService` (matrix read, bulk-upsert atomic + audit, copy-previous, FX aggregation) (§5).
- Report services (per phase): `ExpectedIncomeRegistryService` (R1), `IncomeScheduleService` (R2), `BestManagerService` (R3), `TaskMatrixService` (R4), `ConversionReportService` (R5), `ProductIncomeService` (R6).
- `plans.manage` permission (§8) — **not in `RolePermissionSeeder` today**.
- Endpoints P-1…P-3 (matrix-GET, bulk-upsert, copy-previous) + R1–R6 report endpoints (§6) + FormRequests (§7) + Resources.
- `config('crm.plans.*')` block: best-manager points formula (R3), default conversion pairs (R5), fact-source flag (§4.4).

### 1.3 ⚠️ Anchor decisions (resolve the scope/period ambiguities up-front)

Two findings from the real schema, applied everywhere below:

1. **Team/funnel axis = `pipeline_id`, NOT `department`.** Same finding as МК §1.3: "MACRO Global" and "MACRO AI Global" are **pipelines** (`AmoPipelineSeeder`, sort_order 0/1), not departments. So the `plan_targets.scope_type='pipeline'` level uses `pipeline_id`. The `department`-level mentioned in the plan §2 is **dropped from the schema for this sprint** — the three real levels are **user / pipeline / company-wide (both scope keys NULL)**. (If product later wants an org-department split, it is additive — a fourth `scope_type='department'` value + a nullable `scope_department_id`. Flagged for `reviewer`.)

2. **Продуктовая линейка = `catalog_product_groups`, NOT a menu of hard-coded lines.** SpaceCRM's CRM/ERP/BANK/SALES map to our `catalog_product_groups` rows. R6 `product_income` plans key on `catalog_product_groups.id` (stored in `scope_product_group_id`). No new taxonomy.

---

## 2. Data model

All money = **integer kopecks** (`bigInteger`, cast `'integer'`) — never float/decimal (`docs/backend-standard.md §5`, matches Deal/МК). Counts = plain integers. Percentages/rates = small ints. `config` params → `jsonb` (config only, never money-as-float, matches МК §2.2.1).

### 2.1 `plan_targets` (universal plan cell — the sprint's core table)

Table `plan_targets`. Owning context: `Sales`. **One row = one plannable cell** = (metric × scope × period × layer). The whole sprint reads/writes this one table; reports read facts from live models and join plans from here.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `metric` | string(24) | enum `PlanMetric` (§3.1): `new_income | expected_deals | tasks_completed | conversion | product_income`. |
| `scope_type` | string(12) | enum `PlanScopeType` (§3.2): `user | pipeline | company`. Which axis this cell targets. |
| `scope_user_id` | FK → users, cascadeOnDelete, **nullable** | set ⇔ `scope_type='user'`. |
| `scope_pipeline_id` | FK → pipelines, cascadeOnDelete, **nullable** | set ⇔ `scope_type='pipeline'`. |
| `scope_product_group_id` | FK → catalog_product_groups, cascadeOnDelete, **nullable** | product-line dimension for `metric='product_income'` — orthogonal to scope_type (a product_income plan is company-wide by line, so scope_type='company' + this FK set). See §3.3. |
| `period_year` | smallint | always set. |
| `period_month` | smallint, **nullable** | 1..12 for monthly cells; **NULL = annual-total cell** (a year-level plan not tied to a month). |
| `layer` | string(10) | enum `PlanLayer` (§3.4): `operative | annual`. Operative = revisable; annual = fixed. Both coexist per (metric, scope, period). |
| `value_kopecks` | bigint, **nullable** | money metrics (`new_income`, `product_income`) — kopecks. NULL for count metrics. |
| `value_count` | integer, **nullable** | count metrics (`expected_deals`, `tasks_completed`) — integer. NULL for money metrics. |
| `currency` | string(3), **nullable** | set ⇔ money metric; the currency `value_kopecks` is denominated in. NULL for count metrics. |
| `config` | jsonb, **nullable** | metric-parameter bag — see §3.5 (task kind, conversion pair, etc.). Config only, never money-as-float. |
| `created_by_id` | FK → users, nullOnDelete, nullable | who first created the cell. |
| `updated_by_id` | FK → users, nullOnDelete, nullable | who last touched it (denormalised «кто менял» — full history in `plan_target_audits`). |
| `timestamps` | | |

**Uniqueness — the identity of a cell.** UNIQUE on **`(metric, scope_type, scope_user_id, scope_pipeline_id, scope_product_group_id, period_year, period_month, layer)`**.
- Portability note for `sales-backender`: PostgreSQL treats each NULL as distinct, so a naïve UNIQUE index over nullable scope columns does NOT enforce "one cell per company-wide/annual plan". **Enforce identity in the Service** (`upsert` resolves the existing row by a normalized WHERE with `whereNull` on the unset scope columns) AND add a partial-safe surrogate: store a computed non-null `scope_key` string column (e.g. `"user:12"`, `"pipeline:1"`, `"company"`, `"pg:3"`) and put the DB UNIQUE on `(metric, scope_key, period_year, period_month_key, layer)` where `period_month_key = COALESCE(period_month, 0)`. This gives a real DB guard that survives NULLs. **Recommended: the `scope_key` + `period_month_key` approach** — deterministic, index-friendly, testable on both pgsql and sqlite.

**Indexes** (hot WHERE/ORDER BY):
- UNIQUE `(metric, scope_key, period_year, period_month_key, layer)` (identity, per above).
- `index(metric, period_year, layer)` — matrix reads (a whole metric's grid for a year).
- `index(scope_pipeline_id, period_year)` — pipeline rollups.
- `index(scope_product_group_id, period_year)` — R6 product matrix.

**Casts:** `value_kopecks`/`value_count` → integer; `period_year`/`period_month` → integer; `config` → array; `metric`/`scope_type`/`layer` → their enums.

### 2.2 `plan_target_audits` (append-only change log — «кто/когда/старое→новое»)

Table `plan_target_audits`. Pattern = `DealAudit` verbatim (append-only, `UPDATED_AT = null`, one row per change). Requested explicitly in the sprint plan §2 ("Аудит изменений планов").

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `plan_target_id` | FK → plan_targets, **nullOnDelete** (see as-built note) | the cell that changed. |
| `user_id` | FK → users, nullOnDelete, nullable | actor. |
| `field` | string(24) | which field changed — `value_kopecks | value_count | currency | config` (usually the value). |
| `old_value` | string, nullable | JSON-encoded scalar (kopecks int / count int / currency / config-json). NULL on create. |
| `new_value` | string, nullable | JSON-encoded scalar. |
| `created_at` | timestamptz | `UPDATED_AT = null` — rows are never mutated. |

- **Index** `(plan_target_id, created_at)` — history read for a cell.
- Written by `PlanTargetService::upsertCells` inside the same transaction as the value write (§5). A brand-new cell logs one row with `old_value=null`; an unchanged value writes NO audit row (idempotent bulk-upsert must not spam the log).

> **As-built (Ф1, reviewer 2026-07-03):** `plan_target_id` ships as **`nullOnDelete`, NOT `cascadeOnDelete`** as the table above originally stated. Clearing a grid input deletes the cell (§O10) and records that deletion as a `value_*` change-to-null audit row **in the same transaction**; a cascade FK would then wipe that just-written "deleted" row the instant the cell is removed, destroying the very history this table exists to keep. `nullOnDelete` keeps the append-only record intact (`plan_target_id` goes null after the cell is gone). Correct call.

### 2.3 Model surface (thin — logic in Service, per ARCHITECTURE.md §1)

`Sales/Models/PlanTarget.php` — fillable/casts/relations (`scopeUser`, `scopePipeline`, `scopeProductGroup`, `audits() hasMany`, `createdBy`, `updatedBy`) only. `Sales/Models/PlanTargetAudit.php` — fillable/casts/`planTarget()`/`user()` only. Both carry factories (used in tests).

> **As-built (Ф1, reviewer 2026-07-03):** the scope relations ship as **`targetUser()` / `targetPipeline()` / `targetProductGroup()`**, NOT `scopeUser*` as the prose suggested. Eloquent reserves the `scope*` method prefix exclusively for local query scopes (`Builder $query` first param) — a relation named `scopeUser()` would collide with Eloquent's scope dispatch and break `PlanTarget::query()->user(...)`. The `target*` naming is the correct, codebase-consistent choice.

---

## 3. Enums & metric configuration

### 3.1 `PlanMetric` (`Sales/Enums/PlanMetric.php`)

```php
enum PlanMetric: string {
    case NewIncome      = 'new_income';       // money — new revenue plan (SpaceCRM «Новые поступления», total)
    case ExpectedDeals  = 'expected_deals';   // count — number of deals expected to close/pay
    case TasksCompleted = 'tasks_completed';  // count — completed activities of a given kind (SpaceCRM «Закрытие задач»)
    case Conversion     = 'conversion';       // count/pct — a конверсия pair target (SpaceCRM «Конверсии»)
    case ProductIncome  = 'product_income';   // money — income plan per product line (SpaceCRM «Поступления»)

    public static function values(): array { /* map */ }
    public function isMoney(): bool { return in_array($this, [self::NewIncome, self::ProductIncome], true); }
    public function isCount(): bool { return ! $this->isMoney(); }
}
```

`isMoney()` drives which value column (`value_kopecks` + `currency`) vs (`value_count`) is populated — the FormRequest and Service enforce exactly one is set (§7).

### 3.2 `PlanScopeType` (`Sales/Enums/PlanScopeType.php`)

```php
enum PlanScopeType: string {
    case User     = 'user';      // per-manager (scope_user_id set)
    case Pipeline = 'pipeline';  // per-funnel Global / Global AI (scope_pipeline_id set)
    case Company  = 'company';   // company-wide (both scope FKs null; product_group may still be set for R6)
}
```

### 3.3 Scope/metric compatibility matrix (Service + FormRequest enforce)

| metric | valid scope_type(s) | product_group | value | Report |
|---|---|---|---|---|
| `new_income` | user, pipeline, company | — | kopecks + currency | R2 schedule, R6 totals row, МК link (§Ф6) |
| `expected_deals` | user, pipeline, company | — | count | R1 registry target |
| `tasks_completed` | user, pipeline | — | count | R4 task matrix |
| `conversion` | user, pipeline | — | count (or pct — see §3.5) | R5 conversions |
| `product_income` | company (per line) | **required** | kopecks + currency | R6 «Поступления» |

Any other combination → 422 (FormRequest `withValidator`). `product_income` is the only metric that sets `scope_product_group_id`; it is always `scope_type='company'` for this sprint (per-line company-wide, matching SpaceCRM which plans product×month, not manager×product).

### 3.4 `PlanLayer` (`Sales/Enums/PlanLayer.php`)

```php
enum PlanLayer: string { case Operative = 'operative'; case Annual = 'annual'; }
```

Operative and Annual are **independent cells** (SpaceCRM's «Оперативный | Годовой» toggle) — same (metric, scope, period) can have both. The matrix-GET takes `layer` as a filter and returns one layer's grid.

### 3.5 `config` jsonb shape (per metric)

Config/metadata only — never money. The `config` bag disambiguates the metric's parameter:

```jsonc
// metric = tasks_completed   — which activity kind is planned
{ "activity_kind": "meeting" }   // one of ActivityType::values(): call|meeting|task|note|follow_up|presentation
// (special sentinel "ftm" → count FTM-qualified meetings via Activity::scopeFtmCounted, not raw meetings)

// metric = conversion   — the числитель/знаменатель pair
{ "numerator":   { "type": "task",  "kind": "meeting" },      // task-count numerator
  "denominator": { "type": "task",  "kind": "call" },         // task-count denominator
  "unit": "pct" }                                             // value stored as pct target (value_count = 85 → 85%)
// OR a stage-based (honest, our advantage) conversion:
{ "numerator":   { "type": "stage", "stage_id": 7 },          // reached stage N (from deal_stage_history)
  "denominator": { "type": "stage", "stage_id": 3 },
  "unit": "pct" }
// OR a deal-based pair (SpaceCRM «Презентация/Сделка»):
{ "numerator":   { "type": "task",  "kind": "presentation" },
  "denominator": { "type": "deal",  "status": "won" },
  "unit": "pct" }

// metric = new_income / expected_deals / product_income → config null (no extra param needed)
```

For `conversion` the plan value is the **target %** (`value_count = 85` means "plan 85% conversion"), not a raw count — `unit:"pct"` marks it. Report R5 computes the fact conversion from the pair's live numerator/denominator counts and scores fact-% against plan-% via `scorePct`.

---

## 4. Multi-currency, fact-source, period recognition (cross-cutting rules)

### 4.1 Multi-currency (identical mechanics to МК §4.4 / dashboard)

- Each money plan cell (`new_income`, `product_income`) carries its own `currency` + `value_kopecks`.
- Aggregation & план/факт comparison happen in **base currency** (`config('crm.currencies.default')` = RUB) via `ExchangeRateService::convertAmount(kopecks, cell.currency, base, ratesDate)`.
- `ratesDate` = **1st of the plan's month** (`Carbon::create(year, month, 1)`), matching МК + `salary.py`. For an **annual cell (period_month NULL)** the annual plan is the **sum of its 12 monthly cells each converted at their own month's 1st-of-month rate** (plan §4: "Годовой = сумма месячных в base"). A standalone annual-only cell (no monthly children) converts at the 1st-of-**January** rate of that year.
- Missing rate → `convertAmount` returns null → set `multi_currency_warning=true` in the response + fall back to the raw amount (defensive, same as `ManagerKpiService::normalisedPlanKopecks`).
- Response money is always integer kopecks in base currency; per-currency breakdown is surfaced via a `currency_breakdown` block for the info-popover (matrix response §6.1).

> **As-built known-gap (Ф1, reviewer 2026-07-03):** the **fact** side of the matrix converts foreign-currency won deals to base at the month's 1st-of-month rate as specified (tested in `PlanMatrixMultiCurrencyTest`). The **plan** side does NOT yet convert — `PlanTargetService::buildMatrix` returns `plan_kopecks` as the raw stored `value_kopecks` in the cell's own currency, so a non-RUB plan cell would be compared raw against a base-currency fact. Ф1 is **RUB-first** (plans are entered in RUB in practice), so this does not bite in the shipped scope. **Fix when multi-currency PLANS are exercised (Ф2+):** convert the plan cell via `ExchangeRateService::convertAmount(value_kopecks, cell.currency, base, ratesDate)` before the plan/fact %, symmetric with the fact side, with the same `multi_currency_warning` + raw-fallback semantics.

### 4.2 Fact-source seam (interim = won_deals, identical shape to МК)

Money **fact** for the plan-vs-fact comparison is interim = **won deals** (Phase A), exactly as МК. After the Finance sprint (Ф7) the fact flips to **payment fact** without a schema change — the report services resolve the money-fact through the same `FactSource` abstraction the МК contract defines (`WonDealsFactSource` now, `PaymentsFactSource` later). **Reuse the МК FactSource** — do NOT introduce a second fact abstraction. The report response carries `"fact_source": "won_deals"` in meta so the FE badges "расчёт по выигранным, не по оплате" (same convention as `ManagerKpiService` meta `income_source`).

### 4.3 Period recognition (reuse dashboard `effectiveDateExpr`)

For money facts by month, a won deal counts in the month of its **effective recognition date** = `COALESCE(closed_at, signed_at, paid_at, stage_changed_at)` (dashboard `effectiveDateExpr`). R1's «дожим» keys on `expected_payment_date` (planned pay date), not recognition — an open deal whose planned pay date already passed. These two are distinct on purpose: recognition = when revenue is booked; expected_payment_date = when it was promised.

### 4.4 `config/crm.php` additions (Ф0 declares, Ф1+ populate)

```php
'plans' => [
    // interim money-fact source until Finance (mirrors МК). 'won_deals' | 'payments'
    'fact_source' => env('CRM_PLANS_FACT_SOURCE', 'won_deals'),

    // R3 «Лучший менеджер» points formula — weights applied to yearly aggregates.
    // points = income_points + deal_points + support_points, where
    // income_points = round(new_income_base_kopecks / points.income_divisor_kopecks)
    'best_manager' => [
        'income_divisor_kopecks' => 100_000_00,   // 1 point per 100 000 ₽ of НП (tune with product)
        'points_per_won_deal'    => 10,
        'points_per_support'     => 5,
    ],

    // R5 canonical default conversion pairs (seeded into the matrix as suggestions;
    // custom pairs are stored as plan_targets.config). Stage ids resolved per-pipeline at read.
    'conversions' => [
        'default_pairs' => [
            // task-based (SpaceCRM parity)
            ['num' => ['type' => 'task', 'kind' => 'meeting'],      'den' => ['type' => 'task', 'kind' => 'call']],
            ['num' => ['type' => 'task', 'kind' => 'presentation'], 'den' => ['type' => 'deal', 'status' => 'won']],
        ],
    ],
],
```

R3's exact point weights and R5's default pairs are **config, not code** (plan §5 Ф3 "формула — конфиг в config/crm.php") — tunable without a deploy of logic.

---

## 5. `PlanTargetService` (public surface — signatures, not implementation)

Lives in `Sales/Services/Planning/PlanTargetService.php`. Pure where possible; DB/FX via injected services (mirrors `SalesDashboardService`/`ManagerKpiService`). **Ф1 builds this for `new_income` end-to-end**; Ф2+ reuse it unchanged for the other metrics.

```php
final class PlanTargetService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ManagerKpiService $kpiService,   // money-fact delegate (interim won_deals)
    ) {}

    // ---- Matrix read (P-1) ----
    /**
     * Plan+fact matrix for one metric × layer × period, expanded over the scope axis.
     * For metric=new_income scope=user → rows = managers (visibility-scoped), cols = 12 months + annual.
     * Each cell: { plan_kopecks|plan_count, fact_kopecks|fact_count, pct, badge, currency, has_plan }.
     * Facts are computed live (delegated) and joined to plan cells from plan_targets.
     * @return array<string,mixed>  see §6.1 shape
     */
    public function buildMatrix(PlanMatrixQuery $query, User $viewer): array;

    // ---- Bulk upsert (P-2) — plans.manage ----
    /**
     * Atomic (DB::transaction) create-or-update of many cells. Idempotent by the cell
     * identity (§2.1). Writes a plan_target_audits row per CHANGED cell (unchanged → no audit).
     * Resolves scope_key + period_month_key for the UNIQUE guard. Returns the affected cells.
     * @param list<UpsertCellData> $cells
     * @return list<PlanTarget>
     */
    public function upsertCells(array $cells, User $actor): array;

    // ---- Copy previous period (P-3) ----
    /**
     * Clone all cells of (metric, layer, sourcePeriod) into targetPeriod (same scope keys,
     * same values). Skips cells that already exist in the target (no overwrite). Audited as creates.
     * @return int  number of cells created
     */
    public function copyPreviousPeriod(CopyPeriodData $data, User $actor): int;

    // ---- Fact helpers (delegated; one per metric, added per phase) ----
    /** money fact per user/pipeline/company for a month — delegates to ManagerKpiService/FactSource */
    public function moneyFact(PlanScopeType $scope, ?int $scopeId, int $year, int $month, bool &$fxWarn): int;
    /** count fact for tasks_completed(kind) / expected_deals — GROUP BY, no N+1 */
    public function countFact(PlanMetric $metric, array $config, PlanScopeType $scope, ?int $scopeId, int $year, int $month): int;

    // ---- Pure helpers (reuse ManagerKpiService) ----
    // scorePct/scoreBadge are REUSED from ManagerKpiService (inject or call) — not re-implemented.
}
```

**Reuse-gate (mandatory):** `scorePct`/`scoreBadge` come from `ManagerKpiService`; money-fact from `ManagerKpiService::personalIncomeFact`/`teamKpiBatch` (or a batched pipeline query built on the same won-deal + FX pattern); read-scope from `VisibilityResolver`; FX from `ExchangeRateService`. `PlanTargetService` orchestrates — it never re-queries won deals or re-derives a badge (`docs/backend-standard.md §6`).

---

## 6. Endpoints — routes, shapes, Resources

All under `/api`, `auth:sanctum`. **All responses via hand-written `JsonResource` — never raw arrays** (`docs/backend-standard.md §1`). Money = integer kopecks (format on FE). Report reads gated by visibility (the aggregator scopes rows); plan writes gated by `plans.manage`.

Route groups:
```php
// planning matrix (read = any authed, scoped; write = plans.manage)
Route::prefix('plans')->name('plans.')->group(function () {
    Route::get('matrix', [PlanMatrixController::class, 'index'])->name('matrix');                 // P-1
    Route::post('cells', [PlanMatrixController::class, 'bulkUpsert'])                              // P-2
        ->middleware('can:plans.manage')->name('cells.upsert');
    Route::post('copy-previous', [PlanMatrixController::class, 'copyPrevious'])                    // P-3
        ->middleware('can:plans.manage')->name('copy-previous');
});
// reports (read, visibility-scoped in the aggregator)
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('registry',      [ReportsController::class, 'registry'])->name('registry');        // R1
    Route::get('income-schedule',[ReportsController::class, 'incomeSchedule'])->name('income-schedule'); // R2
    Route::get('best-manager',  [ReportsController::class, 'bestManager'])->name('best-manager');  // R3
    Route::get('task-matrix',   [ReportsController::class, 'taskMatrix'])->name('task-matrix');    // R4
    Route::get('conversions',   [ReportsController::class, 'conversions'])->name('conversions');   // R5
    Route::get('product-income',[ReportsController::class, 'productIncome'])->name('product-income'); // R6
});
```
Controllers under `app/Http/Controllers/Sales/Planning/` (thin → Service → Resource).

### 6.1 P-1 · `GET /api/plans/matrix` — plan+fact matrix (inline-grid source)

Query: `?metric=new_income&scope_type=user&layer=operative&year=2026&pipeline_id=1&month=<optional single-month drill>`.
`FormRequest` = `PlanMatrixRequest` (§7.1). Controller → `PlanTargetService::buildMatrix()` → `PlanMatrixResource` (`$wrap=null`, root-level like `KpiResource`).

```jsonc
{
  "meta": {
    "metric": "new_income",
    "scope_type": "user",
    "layer": "operative",
    "year": 2026,
    "pipeline_id": 1,
    "value_kind": "money",                 // "money" | "count" — FE picks kopeck-formatter vs int
    "base_currency": "RUB",
    "fact_source": "won_deals",            // badge "расчёт по выигранным"
    "multi_currency_warning": false,
    "can_edit": true                       // $user->can('plans.manage') — FE shows inputs
  },
  "columns": [                             // 12 months + annual total
    { "key": "1",  "label": "Янв", "period_month": 1 },
    { "key": "2",  "label": "Фев", "period_month": 2 },
    // … 3..12 …
    { "key": "annual", "label": "Год", "period_month": null }
  ],
  "rows": [                                // scope-axis expansion (managers here), visibility-scoped
    {
      "scope": { "type": "user", "id": 12, "label": "Илья Рогов" },
      "cells": {
        "1": { "plan_kopecks": 5000000000, "fact_kopecks": 3200000000, "pct": 64, "badge": "danger",
               "currency": "RUB", "has_plan": true, "plan_id": 8801,
               "currency_breakdown": [ { "currency": "RUB", "plan_kopecks": 5000000000 } ] },
      // … months 2..12 …
        "annual": { "plan_kopecks": 60000000000, "fact_kopecks": 41800000000, "pct": 70, "badge": "danger",
                    "currency": "RUB", "has_plan": true, "plan_id": null }   // annual = derived sum (plan_id null)
      }
    }
    // … more manager rows …
  ],
  "totals": {                              // ИТОГО row (sum across scope rows, per column, in base)
    "1": { "plan_kopecks": 20000000000, "fact_kopecks": 14300000000, "pct": 72, "badge": "danger" },
    "annual": { "plan_kopecks": 240000000000, "fact_kopecks": 171000000000, "pct": 71, "badge": "danger" }
  }
}
```

Notes for FE: `value_kind='count'` metrics carry `plan_count`/`fact_count` instead of `*_kopecks`/`currency`. `pct` may be `null` (no plan → «—», badge `none`). `plan_id` present on real stored monthly cells (for optimistic single-cell PATCH via bulk-upsert), `null` on the derived annual column. Annual column is **read-only derived** unless the metric has a standalone annual cell (period_month NULL) authored directly — then `plan_id` is set and it is editable.

### 6.2 P-2 · `POST /api/plans/cells` — bulk upsert cells (`can:plans.manage`)

`FormRequest` = `BulkUpsertCellsRequest` (§7.2). Idempotent atomic upsert + audit. Body:
```jsonc
{
  "cells": [
    { "metric": "new_income", "scope_type": "user", "scope_user_id": 12,
      "period_year": 2026, "period_month": 1, "layer": "operative",
      "value_kopecks": 5000000000, "currency": "RUB" },
    { "metric": "tasks_completed", "scope_type": "user", "scope_user_id": 12,
      "period_year": 2026, "period_month": 1, "layer": "operative",
      "value_count": 40, "config": { "activity_kind": "meeting" } },
    { "metric": "product_income", "scope_type": "company", "scope_product_group_id": 3,
      "period_year": 2026, "period_month": 1, "layer": "annual",
      "value_kopecks": 12000000000, "currency": "RUB" }
  ]
}
```
Response: `200` → `PlanCellCollection` (the affected cells, same cell shape as a matrix cell + `plan_id`). `422` on validation (bad metric/scope combo, both/neither value set, currency missing on money). Setting a value to `null`/empty on an existing cell = **delete the cell** (clearing the grid input removes the plan) — audited as a change to null.

### 6.3 P-3 · `POST /api/plans/copy-previous` — copy prior period (`can:plans.manage`)

`FormRequest` = `CopyPreviousRequest` (§7.3). Body `{ "metric": "new_income", "layer": "operative", "from_year": 2025, "from_month": 12, "to_year": 2026, "to_month": 1, "scope_type": "user", "pipeline_id": 1 }`. Copies all matching cells that don't already exist in the target. Response `200` → `{ "data": { "created": 14 } }`. Copying a whole-year (omit `*_month`) clones all 12 months.

### 6.4 R1 · `GET /api/reports/registry` — Реестр + Дожим

Query `?year=2026&month=1&pipeline_id=1&product_group_id=<opt>&manager_id=<opt>`. Aggregator `ExpectedIncomeRegistryService` (Ф2). Two lists, visibility-scoped. Resource `RegistryReportResource`.
```jsonc
{
  "meta": { "period": { "year": 2026, "month": 1, "label": "Январь 2026" }, "pipeline_id": 1,
            "base_currency": "RUB", "fact_source": "won_deals", "multi_currency_warning": false },
  "expected": {                            // open deals with expected_payment_date in the month
    "rows": [
      { "deal_id": 42, "title": "Qala Dev — CRM", "company_name": "Qala Dev",
        "owner": { "id": 12, "full_name": "Илья Рогов" },
        "deal_type": "direct",             // «Прямая/СБС» — derived (see §Open Q O3)
        "products": [ { "name": "MacroSales CRM", "group": "MacroSales CRM" } ],
        "amount_kopecks": 985750000, "currency": "UZS", "amount_base_kopecks": 6522000,
        "fact_kopecks": 0,                 // paid so far (paid_amount) — 0 until Finance (Ф7)
        "expected_payment_date": "2026-01-20", "signed_at": "2025-12-15",
        "last_task": { "result_text": "Ждём оплату до 20/01", "at": "2026-01-08T10:00:00Z" } }
    ],
    "total_base_kopecks": 42800000
  },
  "squeeze": {                             // «дожим»: open, expected_payment_date < today, paid_at null
    "rows": [ /* same row shape */ ],
    "total_base_kopecks": 394376500,
    "no_date_count": 3                     // open deals with NO expected_payment_date (UI warns — plan §7 risk)
  }
}
```

### 6.5 R2 · `GET /api/reports/income-schedule` — График НП (день-календарь)

Query `?year=2026&month=1&pipeline_id=1`. Aggregator `IncomeScheduleService` (Ф2). Resource `IncomeScheduleResource`. ECharts-ready.
```jsonc
{
  "meta": { "period": { "year": 2026, "month": 1, "label": "Январь 2026" }, "days_in_month": 31,
            "base_currency": "RUB", "multi_currency_warning": false },
  "plan_total_base_kopecks": 60000000000,  // new_income plan for the month (from plan_targets)
  "days": [
    { "day": 1, "is_weekend": false,
      "fact_base_kopecks": 0,              // paid on this day (won recognition date), base
      "expected_base_kopecks": 500000000,  // expected_payment_date == this day, open
      "squeeze_base_kopecks": 0,           // overdue expected rolled onto today
      "cumulative_plan_base_kopecks": 1935483871,   // plan spread linearly across working days
      "cumulative_fact_base_kopecks": 0 }
    // … day 2..31 …
  ]
}
```
Legend colours (plan/squeeze/fact/weekend) are FE concerns; the payload gives the four kopeck series per day + cumulative plan/fact.

### 6.6 R3 · `GET /api/reports/best-manager` — Лучший менеджер

Query `?year=2026&mode=standard&pipeline_id=<opt>`. Aggregator `BestManagerService` (Ф3). Points formula from `config('crm.plans.best_manager')` (§4.4). Resource `BestManagerResource`.
```jsonc
{
  "meta": { "year": 2026, "mode": "standard", "base_currency": "RUB", "multi_currency_warning": false },
  "rows": [
    { "rank": 1, "user": { "id": 12, "full_name": "Илья Рогов" }, "division": "MACRO Global",
      "won_deals": 34, "supports": 12, "new_income_base_kopecks": 41800000000,
      "avg_check_base_kopecks": 1229411764, "income_points": 418, "total_points": 763,
      "in_standings": true }
    // … more, DESC by total_points …
  ],
  "leader": { "user": { "id": 12, "full_name": "Илья Рогов" }, "division": "MACRO Global",
              "won_deals": 34, "total_points": 763, "new_income_base_kopecks": 41800000000 }
}
```
`mode=absolute` includes out-of-standings accounts (service/admin) with `in_standings:false` and `total_points` present; `mode=standard` marks them `in_standings:false` and excludes from ranking (SpaceCRM «Стандартный/Абсолютный»).

### 6.7 R4 · `GET /api/reports/task-matrix` — Закрытие задач (kind × user × 12 мес)

Query `?year=2026&layer=operative&pipeline_id=<opt>&kind=<opt>`. Aggregator `TaskMatrixService` (Ф4). Plan from `plan_targets(metric=tasks_completed)`; fact = completed `Activity` counts grouped by `kind × responsible_id × month`. Resource shape = the P-1 matrix shape with `value_kind:"count"`, one **matrix per activity kind** (call/meeting/task/follow_up/presentation) plus an "all" aggregate:
```jsonc
{
  "meta": { "year": 2026, "layer": "operative", "value_kind": "count", "can_edit": true },
  "groups": [
    { "kind": "meeting", "label": "Встречи",
      "columns": [ /* 12 months + annual, as P-1 */ ],
      "rows": [ { "scope": { "type": "user", "id": 12, "label": "Илья Рогов" },
                  "cells": { "1": { "plan_count": 40, "fact_count": 38, "pct": 95, "badge": "warning", "plan_id": 9001 } } } ],
      "totals": { "1": { "plan_count": 120, "fact_count": 110, "pct": 92, "badge": "warning" } } }
    // … other kinds …
  ]
}
```

### 6.8 R5 · `GET /api/reports/conversions` — Конверсии

Query `?year=2026&layer=operative&pipeline_id=1&scope_type=user|pipeline`. Aggregator `ConversionReportService` (Ф4). Three blocks (SpaceCRM parity + our stage-honest layer): `general` (auto task-ratio pairs), `custom` (user pairs from `plan_targets.config`), `stage` (honest конверсии from `deal_stage_history` — our advantage). Resource `ConversionReportResource`:
```jsonc
{
  "meta": { "year": 2026, "layer": "operative", "pipeline_id": 1 },
  "custom": [
    { "plan_id": 9100, "name": "Презентация / Сделка",
      "numerator": { "type": "task", "kind": "presentation" }, "denominator": { "type": "deal", "status": "won" },
      "columns": [ /* 12 months + annual */ ],
      "cells": { "1": { "plan_pct": 25, "fact_pct": 22, "num_count": 44, "den_count": 200, "pct": 88, "badge": "warning" } } }
  ],
  "stage": [                               // honest stage conversion (no plan input — fact only, our козырь)
    { "name": "2. qualification → 3. schedule", "from_stage_id": 5, "to_stage_id": 6,
      "cells": { "1": { "fact_pct": 61, "num_count": 61, "den_count": 100 } } }
  ]
}
```
`fact_pct` = num_count/den_count×100; `pct` = `scorePct(fact_pct, plan_pct)` → badge. Stage block has no plan (fact-only, honest).

### 6.9 R6 · `GET /api/reports/product-income` — Поступления по линейкам

Query `?year=2026&layer=operative`. Aggregator `ProductIncomeService` (Ф5). Plan from `plan_targets(metric=product_income)` per `catalog_product_groups`; fact = won-deal income attributed to the deal's product line(s); expected = open deals `expected_payment_date` in month by line. Resource `ProductIncomeResource` — rows = product groups × months, each column a group of `{plan, expected, total, total_pct, fact, fact_pct}` (SpaceCRM «Прогноз | Поступления»):
```jsonc
{
  "meta": { "year": 2026, "layer": "operative", "base_currency": "RUB",
            "fact_source": "won_deals", "multi_currency_warning": false, "can_edit": true },
  "columns": [ /* 12 months + annual */ ],
  "rows": [
    { "scope": { "type": "product_group", "id": 1, "label": "MacroSales CRM" },
      "cells": { "1": { "plan_kopecks": 12000000000, "expected_kopecks": 3000000000,
                        "fact_kopecks": 8000000000, "total_kopecks": 11000000000,
                        "total_pct": 92, "fact_pct": 67, "badge": "warning", "plan_id": 9200, "currency": "RUB" } } }
  ],
  "totals": { "1": { "plan_kopecks": 60000000000, "fact_kopecks": 41000000000, "total_pct": 88, "badge": "warning" } }
}
```
`total_kopecks = fact + expected` (SpaceCRM «Всего»); `total_pct = total/plan`; `fact_pct = fact/plan`. Двойной прогноз (Ф4/Ф5): a parallel weighted number from `SalesDashboardService::forecastData` may sit beside `total` in a later pass (plan §Ф4 «двойной прогноз»); Ф5 ships the additive one first.

---

## 7. FormRequest rules

### 7.1 `PlanMatrixRequest` (P-1)
`authorize(): true` (read is scoped in the aggregator). Rules: `metric` required `Rule::in(PlanMetric::values())`; `scope_type` required `Rule::in(PlanScopeType::values())`; `layer` required `Rule::in(PlanLayer::values())`; `year` required int 2020..2100; `month` nullable int 1..12; `pipeline_id` nullable exists:pipelines,id. `withValidator`: assert metric×scope compatibility (§3.3).

### 7.2 `BulkUpsertCellsRequest` (P-2)
`authorize(): true` (route gate = `can:plans.manage`). Rules:
- `cells` required, array, min:1, max:400 (guard giant payloads; the SpaceCRM «Закрытие задач» grid was 216 inputs — 400 covers 12 months × managers comfortably).
- `cells.*.metric` required `Rule::in(PlanMetric::values())`.
- `cells.*.scope_type` required `Rule::in(PlanScopeType::values())`.
- `cells.*.scope_user_id` nullable exists:users,id; `cells.*.scope_pipeline_id` nullable exists:pipelines,id; `cells.*.scope_product_group_id` nullable exists:catalog_product_groups,id.
- `cells.*.period_year` required int 2020..2100; `cells.*.period_month` nullable int 1..12.
- `cells.*.layer` required `Rule::in(PlanLayer::values())`.
- `cells.*.value_kopecks` nullable int min:0; `cells.*.value_count` nullable int min:0; `cells.*.currency` nullable string size:3, `Rule::in(config('crm.currencies.supported'))`; `cells.*.config` nullable array.
- `withValidator` (per-cell): (a) metric×scope compatibility (§3.3); (b) money metric ⇒ `value_kopecks` set (or null=delete) + `currency` required when value present + `value_count` must be null; count metric ⇒ `value_count` set (or null=delete) + `value_kopecks`/`currency` must be null; (c) `product_income` ⇒ `scope_product_group_id` required & `scope_type=company`; (d) `tasks_completed`/`conversion` ⇒ `config` present & well-formed (§3.5); (e) the correct scope FK is set for the declared `scope_type` and the others are null.

### 7.3 `CopyPreviousRequest` (P-3)
`authorize(): true` (route gate). `metric`/`layer`/`scope_type` as above; `from_year`/`to_year` required int; `from_month`/`to_month` nullable int 1..12 (both null = whole year); `pipeline_id` nullable exists.

### 7.4 Report requests (R1–R6)
Thin `*ReportRequest` per report: `year` required int, `month` (R1/R2) required int 1..12, `layer` (R4/R5/R6) `Rule::in(PlanLayer::values())`, `pipeline_id`/`manager_id`/`product_group_id` nullable exists, `mode` (R3) nullable `in:standard,absolute`. `authorize(): true` (row-visibility enforced in the aggregator via `VisibilityResolver`, not the FormRequest).

---

## 8. Authorization, permission & seeder

### 8.1 New permission `plans.manage`
Add to `RolePermissionSeeder` `DOMAIN_PERMISSIONS` + grants. **Grant to `admin`, `director`** (the plan authors — same pair as `motivation.manage`). Registered on the `sanctum` guard; spatie's `PermissionRegistrar` auto-registers it as a Gate ability → `$user->can('plans.manage')`, `can:plans.manage` middleware, Policy bodies. **No inline role checks** (`docs/backend-standard.md §4`). Idempotent `firstOrCreate` + `syncPermissions` — safe to re-run (prod reseed after deploy, same as МК).

Add to `permissionsForRole`:
- `admin` → already gets all `DOMAIN_PERMISSIONS`.
- `director` → add `'plans.manage'` to its explicit list.

### 8.2 Read authorization (reports + matrix-GET)
Report/matrix **reads are visibility-scoped, not gated** — any authed user calls them; the aggregator applies `VisibilityResolver` so a manager sees only their own row(s) (Own→ own scope_user_id; Department→ subtree; All→ everyone). The matrix's `meta.can_edit = $user->can('plans.manage')` tells the FE whether to render inputs. This mirrors the dashboard/cabinet pattern: no report leaks another manager's numbers, and the write gate is separate from the read gate. **Do NOT add a `plans.view` permission** — visibility scope already governs read.

### 8.3 Boundaries (cross-domain through owning Service only — `docs/backend-standard.md §3`)
| Need | Owner service (inject; never touch table) |
|---|---|
| FX conversion | `Catalog\Services\ExchangeRateService::convertAmount()` |
| Won-deal money fact | `Sales\Services\ManagerKpiService` (same context) |
| Read visibility scope | `Iam\Services\VisibilityResolver` |
| Task/activity counts | `Activity\Services\ActivityService` (batched count queries) |
| Product line of a deal | join `deal_products → catalog_products.group_id` (Sales owns deal_products; Catalog owns products — read-join is allowed for aggregates, same as `SalesDashboardService::topProducts`) |
| Payment fact (Ф7) | Finance public service via МК `PaymentsFactSource` — **not called before Finance** |

---

## 9. Gap analysis & phase split (what Ф1 builds vs Ф2–Ф6)

**Ф0 (this doc):** contract only. Table/enum/endpoint/report shapes fixed. `designer` in parallel: dashboard-hub tabs + inline-matrix + calendar-grid + leader-card TЗ (navy DS).

**Ф1 — Core planning (new_income end-to-end).** `sales-backender` builds:
- Migration `create_plan_targets_table` + `create_plan_target_audits_table` (reversible, pgsql-verified; `scope_key`/`period_month_key` computed columns for the UNIQUE guard; jsonb `config`; indexes §2.1).
- `PlanMetric`/`PlanScopeType`/`PlanLayer` enums; `PlanTarget`/`PlanTargetAudit` models + factories.
- `config/crm.php` `plans` block (§4.4); `plans.manage` in `RolePermissionSeeder`.
- `PlanTargetService`: `buildMatrix` + `upsertCells` (atomic + audit) + `copyPreviousPeriod` + `moneyFact` (delegating to `ManagerKpiService`) — **for `new_income` scope=user only** (other metrics validated but their fact-helpers stubbed to next phases).
- Endpoints P-1/P-2/P-3 + FormRequests §7.1–7.3 + Resources.
- Tests: Unit (scope-key resolution, metric×scope validation, FX annual-sum, audit-on-change-only) + Feature (matrix read visibility-scoped; bulk-upsert 200/422; plans.manage 403 for manager; copy-previous).
- Frontend (sales-frontender): the «Планы» tab with the new_income inline matrix.

**Ф2 — R1 Registry+Squeeze, R2 Schedule.** `ExpectedIncomeRegistryService` + `IncomeScheduleService` over Deal/DealProduct; endpoints R1/R2 + Resources + tests. Reverb realtime hook (план/факт refresh on won/paid).

**Ф3 — R3 Best manager.** `BestManagerService` (yearly points from config); endpoint R6.6 + Resource + tests.

**Ф4 — R4 Tasks + R5 Conversions.** `TaskMatrixService` (reuse `PlanTargetService` matrix for tasks_completed) + `ConversionReportService` (task pairs + `deal_stage_history` honest layer); endpoints R4/R5; двойной прогноз groundwork.

**Ф5 — R6 Product income + Excel export.** `ProductIncomeService` (interim won_deals fact by line); endpoint R6; PhpSpreadsheet export of all reports (reuse `buildXlsx` pattern).

**Ф6 — МК link.** `MotivationCard` plan-column reads `plan_targets(new_income, scope=user, month)` instead of duplicating the number. See §10.

**Ф7 (post-Finance):** money fact flips `won_deals → payments` via МК `PaymentsFactSource` — no schema change (R1 `fact_kopecks`, R2 fact series, R6 fact become real payments).

---

## 10. МК seam (Ф6) — how MotivationCard reads a plan cell (задел, non-breaking)

The МК contract already stores the manager's income plan inside `MotivationCardItem` (manual entry today). Ф6 lets the МК constructor **read** the manager's `new_income` plan from `plan_targets` instead of re-typing it:

- Single lookup: `PlanTargetService` exposes a read helper `newIncomePlanFor(int $userId, int $year, int $month, PlanLayer $layer = Operative): ?int` returning base-currency kopecks (or the raw cell + currency). МК's `MotivationCardService::upsertPlan`/`buildCabinetPayload` calls it to **pre-fill** the commission indicator plan and to show a "plan из отчётов" badge.
- **Non-breaking:** МК keeps its own `plan_amount_kopecks` column; the plan_targets value is a *default/suggestion* surfaced when the МК cell is empty, exactly as МК fix #35 treats a stored 0 as "not manually entered". A manager's МК is never rewritten by a plan_targets change — the link is read-only pull, opt-in per item.
- The reverse (plan_targets reading МК) is NOT built — plan_targets is the source of truth for planning; МК is the salary consequence.
- No change to МК schema or endpoints in Ф0/Ф1. This section is the задел so the two contours converge on one plan number when Ф6 lands.

---

## 11. Open questions — with defaults applied

All defaults chosen so Ф1 can start without blocking; flag `reviewer`/PM if any is wrong.

| # | Question | **Default applied** |
|---|---|---|
| O1 | department-level plans in `plan_targets`? | **No** — three levels only: user / pipeline / company (§1.3). Department is additive later (nullable scope + enum case). Matches the МК pipeline-not-department finding. |
| O2 | UNIQUE over nullable scope columns (PG treats NULLs distinct) | **Computed `scope_key` + `period_month_key` columns** carry the DB UNIQUE (§2.1) — deterministic, sqlite/pgsql-portable, testable. Service also resolves identity with `whereNull` for the upsert. |
| O3 | «Прямая / СБС» deal type (R1) — where does it come from? | **Derived, best-effort.** No `deal_type` column exists. Default: `extra_fields`/tags heuristic → fallback `"direct"`. Flag to product; if they want a first-class field, it's an additive Deal migration (out of Ф0 scope). |
| O4 | Conversion plan value — % target or raw counts? | **% target** (`value_count = 85` → plan 85%, `config.unit="pct"`, §3.5). Fact-% scored against plan-% via `scorePct`. Matches SpaceCRM's «План/Факt/%» conversion display. |
| O5 | R3 points formula weights | **Config** (`config('crm.plans.best_manager')`, §4.4) — tunable without a logic deploy. Starter weights are placeholders to confirm with product. |
| O6 | Annual cell (period_month NULL) — authored or derived? | **Both supported.** Default matrix shows annual as a **derived sum** of the 12 monthly cells (read-only, `plan_id:null`). A directly-authored annual cell (period_month NULL) is allowed and then editable — for metrics planned only yearly. |
| O7 | Interim money fact source | **`won_deals`** (§4.2), reusing МК's `FactSource`. Flips to `payments` in Ф7 with no schema change. `meta.fact_source` badges the approximation. |
| O8 | New rates endpoint for planning? | **No** — reuse `catalog/exchange-rates*` + the embedded `currency_breakdown`/`rates` in payloads (reuse-gate, same as МК §6.4). |
| O9 | R2 cumulative plan distribution across days | **Linear across working days** (weekends carry 0 increment) — SpaceCRM's «Распределение» spreads undated sums; we spread the monthly plan evenly over business days. Product may later want a weighted curve — additive. |
| O10 | Deleting a plan cell | **Empty/null value on an existing cell = delete + audit** (§6.2). Clearing a grid input removes the plan, logged as change-to-null. |

---

## 12. Handoff notes for `sales-backender` (Ф1)

1. Build order: enums → 2 migrations (plan_targets + plan_target_audits, with `scope_key`/`period_month_key`) → 2 models + factories → `config/crm.php` `plans` block → `plans.manage` in `RolePermissionSeeder` (+ director grant) → `PlanTargetService` (buildMatrix, upsertCells atomic+audit, copyPreviousPeriod, moneyFact delegating to ManagerKpiService) → Controllers/FormRequests/Resources (P-1/P-2/P-3) → tests. **new_income scope=user end-to-end only.**
2. **Reuse, do not re-implement:** `scorePct`/`scoreBadge` + won-deal money-fact from `ManagerKpiService`; read-scope from `VisibilityResolver`; FX from `ExchangeRateService`; effective-date recognition from `SalesDashboardService::effectiveDateExpr`; audit pattern from `DealAudit`; xlsx pattern from `SalesDashboardService::buildXlsx`. (`docs/backend-standard.md §6` reuse checklist.)
3. **Money = integer kopecks; counts = integer; config jsonb holds config only, never money-as-float.** Money metric ⇒ (`value_kopecks` + `currency`); count metric ⇒ `value_count`; enforce exactly-one in the Service + FormRequest.
4. **No inline role checks** — `plans.manage` permission + visibility-scoped reads only.
5. **UNIQUE via `scope_key`/`period_month_key`** so NULL scope columns don't defeat the guard on pgsql; verify `migrate` + `migrate:rollback` on pgsql; `php artisan test` green (sqlite :memory:); `pint` clean before handing back.
6. **Frontend depends on §6 response shapes** — if any field name changes during implementation, flag main so the FE contract and PLAN sync via `reviewer` (public-API change). The matrix cell shape (`plan_*/fact_*/pct/badge/plan_id/currency`) is the contract both P-1 and R4/R6 grids build on — keep it stable across metrics.
