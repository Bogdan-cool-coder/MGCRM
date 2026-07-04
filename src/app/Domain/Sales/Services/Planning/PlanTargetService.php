<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Planning;

use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\CopyPeriodData;
use App\Domain\Sales\Data\PlanMatrixQuery;
use App\Domain\Sales\Data\UpsertCellData;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Models\PlanTargetAudit;
use App\Domain\Sales\Services\ManagerKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PlanTargetService — orchestrator for the universal plan cell (contract §5).
 *
 * Ф1 builds this for `new_income` scope=user end-to-end; Ф2+ reuse it
 * unchanged for the other metrics. Reuse-gate (mandatory, contract §5):
 * scorePct/scoreBadge + won-deal money-fact come from ManagerKpiService;
 * read-scope from VisibilityResolver; FX from ExchangeRateService. This
 * service never re-queries won deals or re-derives a badge.
 */
class PlanTargetService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ManagerKpiService $kpiService,
    ) {}

    // -------------------------------------------------------------------------
    // Matrix read (P-1)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed> see contract §6.1 shape
     */
    public function buildMatrix(PlanMatrixQuery $query, User $viewer): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $canEdit = $viewer->can('plans.manage');
        $multiCurrencyWarning = false;

        $rowsMeta = $this->resolveScopeRows($query->scopeType, $viewer);

        $columns = $this->buildColumns();

        // ---- Load ALL plan cells for this (metric, layer, year) in one query ----
        $plans = PlanTarget::query()
            ->where('metric', $query->metric->value)
            ->where('layer', $query->layer->value)
            ->where('period_year', $query->year)
            ->when($query->scopeType === PlanScopeType::User, fn ($q) => $q->whereIn('scope_user_id', array_column($rowsMeta, 'id')))
            ->when($query->scopeType === PlanScopeType::Pipeline, fn ($q) => $q->whereIn('scope_pipeline_id', array_column($rowsMeta, 'id')))
            ->when($query->scopeType === PlanScopeType::Company, fn ($q) => $q->where('scope_type', PlanScopeType::Company->value)->whereNull('scope_product_group_id'))
            ->get()
            ->groupBy(fn (PlanTarget $p) => $this->rowKeyFor($p));

        // ---- Fact per row × month, one batched query per row (GROUP BY month) ----
        $rows = [];

        foreach ($rowsMeta as $rowMeta) {
            $rowPlans = $plans->get((string) $rowMeta['id'], collect());
            /** @var Collection<int, PlanTarget> $rowPlans */
            $monthlyFacts = $this->monthlyMoneyFactFor($query->scopeType, $rowMeta['id'], $query->year, $multiCurrencyWarning);

            $cells = [];
            $annualPlanKopecks = 0;
            $annualFactKopecks = 0;
            $annualHasStoredCell = false;
            $annualPlanId = null;

            foreach (range(1, 12) as $month) {
                $cellPlan = $rowPlans->first(fn (PlanTarget $p) => (int) $p->period_month_key === $month);
                $rawPlanKopecks = $cellPlan?->value_kopecks ?? 0;
                $planKopecks = $cellPlan !== null
                    ? $this->planKopecksInBase($rawPlanKopecks, $cellPlan->currency, $baseCurrency, $query->year, $month, $multiCurrencyWarning)
                    : 0;
                $factKopecks = $monthlyFacts[$month] ?? 0;
                $pct = $this->kpiService->scorePct($factKopecks, $planKopecks);

                $cells[(string) $month] = [
                    'plan_kopecks' => $planKopecks,
                    'fact_kopecks' => $factKopecks,
                    'pct' => $pct,
                    'badge' => $this->kpiService->scoreBadge($pct),
                    'currency' => $cellPlan?->currency ?? $baseCurrency,
                    'has_plan' => $cellPlan !== null,
                    'plan_id' => $cellPlan?->id,
                    'currency_breakdown' => $cellPlan !== null
                        ? [['currency' => $cellPlan->currency, 'plan_kopecks' => $cellPlan->value_kopecks]]
                        : [],
                ];

                $annualPlanKopecks += $planKopecks;
                $annualFactKopecks += $factKopecks;
            }

            // A directly-authored annual cell (period_month NULL) is editable;
            // otherwise annual is a derived read-only sum (contract §O6).
            $annualCell = $rowPlans->first(fn (PlanTarget $p) => $p->period_month === null);

            if ($annualCell !== null) {
                $annualHasStoredCell = true;
                $annualPlanId = $annualCell->id;
                // Standalone annual cell (no monthly children): convert at the
                // 1st-of-January rate of the year (contract §4.1).
                $annualPlanKopecks = $this->planKopecksInBase($annualCell->value_kopecks ?? 0, $annualCell->currency, $baseCurrency, $query->year, 1, $multiCurrencyWarning);
            }

            $annualPct = $this->kpiService->scorePct($annualFactKopecks, $annualPlanKopecks);

            $cells['annual'] = [
                'plan_kopecks' => $annualPlanKopecks,
                'fact_kopecks' => $annualFactKopecks,
                'pct' => $annualPct,
                'badge' => $this->kpiService->scoreBadge($annualPct),
                'currency' => $annualCell?->currency ?? $baseCurrency,
                'has_plan' => $annualHasStoredCell,
                'plan_id' => $annualPlanId,
                'currency_breakdown' => $annualHasStoredCell
                    ? [['currency' => $annualCell->currency, 'plan_kopecks' => $annualCell->value_kopecks]]
                    : [],
            ];

            $rows[] = [
                'scope' => ['type' => $query->scopeType->value, 'id' => $rowMeta['id'], 'label' => $rowMeta['label']],
                'cells' => $cells,
            ];
        }

        $totals = $this->buildTotals($rows);

        return [
            'meta' => [
                'metric' => $query->metric->value,
                'scope_type' => $query->scopeType->value,
                'layer' => $query->layer->value,
                'year' => $query->year,
                'pipeline_id' => $query->pipelineId,
                'value_kind' => $query->metric->isMoney() ? 'money' : 'count',
                'base_currency' => $baseCurrency,
                'fact_source' => config('crm.plans.fact_source', 'won_deals'),
                'multi_currency_warning' => $multiCurrencyWarning,
                'can_edit' => $canEdit,
            ],
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    // -------------------------------------------------------------------------
    // Bulk upsert (P-2)
    // -------------------------------------------------------------------------

    /**
     * @param  list<UpsertCellData>  $cells
     * @return list<PlanTarget>
     */
    public function upsertCells(array $cells, User $actor): array
    {
        return DB::transaction(function () use ($cells, $actor): array {
            $affected = [];

            foreach ($cells as $cell) {
                $result = $this->upsertOneCell($cell, $actor);

                if ($result !== null) {
                    $affected[] = $result;
                }
            }

            return $affected;
        });
    }

    private function upsertOneCell(UpsertCellData $cell, User $actor): ?PlanTarget
    {
        $scopeKey = $this->resolveScopeKey($cell->scopeType, $cell->scopeUserId, $cell->scopePipelineId, $cell->scopeProductGroupId);
        $periodMonthKey = $cell->periodMonth ?? 0;

        $existing = PlanTarget::query()
            ->where('metric', $cell->metric->value)
            ->where('scope_key', $scopeKey)
            ->where('period_year', $cell->periodYear)
            ->where('period_month_key', $periodMonthKey)
            ->where('layer', $cell->layer->value)
            ->lockForUpdate()
            ->first();

        $isDelete = $cell->metric->isMoney()
            ? $cell->valueKopecks === null
            : $cell->valueCount === null;

        if ($isDelete) {
            if ($existing !== null) {
                $field = $cell->metric->isMoney() ? 'value_kopecks' : 'value_count';
                $oldValue = $cell->metric->isMoney() ? $existing->value_kopecks : $existing->value_count;
                $this->writeAudit($existing, $actor, $field, $oldValue, null);
                $existing->delete();
            }

            return null;
        }

        $target = $existing ?? new PlanTarget([
            'metric' => $cell->metric,
            'scope_type' => $cell->scopeType,
            'scope_user_id' => $cell->scopeUserId,
            'scope_pipeline_id' => $cell->scopePipelineId,
            'scope_product_group_id' => $cell->scopeProductGroupId,
            'period_year' => $cell->periodYear,
            'period_month' => $cell->periodMonth,
            'layer' => $cell->layer,
            'scope_key' => $scopeKey,
            'period_month_key' => $periodMonthKey,
            'created_by_id' => $actor->id,
        ]);

        $oldValueKopecks = $target->value_kopecks;
        $oldValueCount = $target->value_count;
        $oldCurrency = $target->currency;
        $oldConfig = $target->config;

        $target->value_kopecks = $cell->metric->isMoney() ? $cell->valueKopecks : null;
        $target->value_count = $cell->metric->isCount() ? $cell->valueCount : null;
        $target->currency = $cell->metric->isMoney() ? $cell->currency : null;
        $target->config = $cell->config;
        $target->updated_by_id = $actor->id;
        $target->save();

        // Audit only what actually changed — idempotent bulk-upsert must not
        // spam the log with a no-op re-save of an unchanged value (contract §2.2).
        if ($cell->metric->isMoney() && $oldValueKopecks !== $target->value_kopecks) {
            $this->writeAudit($target, $actor, 'value_kopecks', $oldValueKopecks, $target->value_kopecks);
        }

        if ($cell->metric->isCount() && $oldValueCount !== $target->value_count) {
            $this->writeAudit($target, $actor, 'value_count', $oldValueCount, $target->value_count);
        }

        if ($oldCurrency !== $target->currency) {
            $this->writeAudit($target, $actor, 'currency', $oldCurrency, $target->currency);
        }

        if ($oldConfig !== $target->config) {
            $this->writeAudit($target, $actor, 'config', $oldConfig, $target->config);
        }

        return $target;
    }

    private function writeAudit(PlanTarget $target, User $actor, string $field, mixed $old, mixed $new): void
    {
        PlanTargetAudit::create([
            'plan_target_id' => $target->id,
            'user_id' => $actor->id,
            'field' => $field,
            'old_value' => $old === null ? null : json_encode($old),
            'new_value' => $new === null ? null : json_encode($new),
        ]);
    }

    // -------------------------------------------------------------------------
    // Copy previous period (P-3)
    // -------------------------------------------------------------------------

    public function copyPreviousPeriod(CopyPeriodData $data, User $actor): int
    {
        return DB::transaction(function () use ($data, $actor): int {
            $sourceMonths = $data->fromMonth !== null ? [$data->fromMonth] : range(1, 12);
            $created = 0;

            foreach ($sourceMonths as $sourceMonth) {
                $targetMonth = $data->toMonth ?? $sourceMonth;

                $sourceQuery = PlanTarget::query()
                    ->where('metric', $data->metric->value)
                    ->where('layer', $data->layer->value)
                    ->where('scope_type', $data->scopeType->value)
                    ->where('period_year', $data->fromYear)
                    ->where('period_month_key', $sourceMonth);

                if ($data->pipelineId !== null) {
                    $sourceQuery->where('scope_pipeline_id', $data->pipelineId);
                }

                foreach ($sourceQuery->get() as $source) {
                    $exists = PlanTarget::query()
                        ->where('metric', $data->metric->value)
                        ->where('scope_key', $source->scope_key)
                        ->where('period_year', $data->toYear)
                        ->where('period_month_key', $targetMonth)
                        ->where('layer', $data->layer->value)
                        ->exists();

                    if ($exists) {
                        continue; // skip — never overwrite an existing target cell
                    }

                    $clone = new PlanTarget([
                        'metric' => $source->metric,
                        'scope_type' => $source->scope_type,
                        'scope_user_id' => $source->scope_user_id,
                        'scope_pipeline_id' => $source->scope_pipeline_id,
                        'scope_product_group_id' => $source->scope_product_group_id,
                        'period_year' => $data->toYear,
                        'period_month' => $data->toMonth ?? ($source->period_month !== null ? $targetMonth : null),
                        'layer' => $source->layer,
                        'value_kopecks' => $source->value_kopecks,
                        'value_count' => $source->value_count,
                        'currency' => $source->currency,
                        'config' => $source->config,
                        'scope_key' => $source->scope_key,
                        'period_month_key' => $targetMonth,
                        'created_by_id' => $actor->id,
                    ]);
                    $clone->save();

                    $this->writeAudit($clone, $actor, $source->metric->isMoney() ? 'value_kopecks' : 'value_count', null, $source->metric->isMoney() ? $source->value_kopecks : $source->value_count);

                    $created++;
                }
            }

            return $created;
        });
    }

    // -------------------------------------------------------------------------
    // Fact helpers (delegated; new_income implemented, others stubbed for Ф2+)
    // -------------------------------------------------------------------------

    /**
     * Money fact for one scope row across all 12 months of a year, in one
     * batched GROUP BY query (no N+1 across rows or months). Currently only
     * new_income / scope=user,pipeline,company is exercised end-to-end (Ф1);
     * delegates to the same won-deal + FX pattern as ManagerKpiService.
     *
     * @return array<int, int> month(1..12) => fact kopecks in base currency
     */
    private function monthlyMoneyFactFor(PlanScopeType $scopeType, int $scopeId, int $year, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');

        // Driver-aware year/month extraction so the query works on both
        // PostgreSQL (production) and SQLite (test :memory: database) —
        // mirrors SalesDashboardService::funnelMetrics' elapsed-seconds guard.
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $yearExpr = $isPg ? 'EXTRACT(YEAR FROM deals.stage_changed_at)' : "strftime('%Y', deals.stage_changed_at)";
        $monthExpr = $isPg ? 'EXTRACT(MONTH FROM deals.stage_changed_at)' : "strftime('%m', deals.stage_changed_at)";

        // Deal::wonDealsBaseQuery() (audit fix, 2026-07-04): raw DB::table
        // queries bypass Eloquent's SoftDeletes global scope — soft-deleted
        // won deals were being counted into every plan cell's fact before
        // this fix. archived_at exclusion was already present; now shared.
        $query = Deal::wonDealsBaseQuery()
            ->whereRaw($yearExpr.' = ?', [(string) $year]);

        match ($scopeType) {
            PlanScopeType::User => $query->where('deals.owner_user_id', $scopeId),
            PlanScopeType::Pipeline => $query->where('deals.pipeline_id', $scopeId),
            PlanScopeType::Company => null, // no filter — company-wide total
        };

        $rows = $query
            ->selectRaw($monthExpr.' as month, SUM(deals.amount) as total_amount, deals.currency')
            ->groupBy(DB::raw($monthExpr), 'deals.currency')
            ->get();

        $totals = array_fill(1, 12, 0);

        foreach ($rows as $row) {
            $month = (int) $row->month;
            $amount = (int) ($row->total_amount ?? 0);
            $currency = (string) ($row->currency ?? $baseCurrency);

            if (strtoupper($currency) === strtoupper($baseCurrency)) {
                $totals[$month] = ($totals[$month] ?? 0) + $amount;

                continue;
            }

            $ratesDate = Carbon::create($year, $month, 1)->toDateString();
            $converted = $this->exchangeRateService->convertAmount($amount, $currency, $baseCurrency, $ratesDate);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $totals[$month] = ($totals[$month] ?? 0) + $converted;
        }

        return $totals;
    }

    /**
     * Convert a stored plan cell's kopecks to base currency, symmetric with the
     * fact side (contract §4.1 known-gap fix). Rate resolved at the 1st-of the
     * cell's period month (same convention as monthlyMoneyFactFor); a missing
     * rate sets multi_currency_warning and falls back to the raw amount so
     * plan/fact comparison never collapses to a misleading 0.
     */
    private function planKopecksInBase(int $amountKopecks, ?string $currency, string $baseCurrency, int $year, int $month, bool &$multiCurrencyWarning): int
    {
        $currency ??= $baseCurrency;

        if (strtoupper($currency) === strtoupper($baseCurrency)) {
            return $amountKopecks;
        }

        $ratesDate = Carbon::create($year, $month, 1)->toDateString();
        $converted = $this->exchangeRateService->convertAmount($amountKopecks, $currency, $baseCurrency, $ratesDate);

        if ($converted === null) {
            $multiCurrencyWarning = true;

            return $amountKopecks;
        }

        return $converted;
    }

    /**
     * count fact for tasks_completed(kind) / expected_deals — stubbed until Ф4.
     * Validated by the FormRequest but the fact helper is not wired for these
     * metrics yet (contract §9 Ф1 scope: new_income scope=user end-to-end only).
     *
     * @param  array<string, mixed>  $config
     */
    public function countFact(PlanMetric $metric, array $config, PlanScopeType $scope, ?int $scopeId, int $year, int $month): int
    {
        return 0;
    }

    // -------------------------------------------------------------------------
    // Scope resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the non-null surrogate scope_key (contract §2.1/§O2).
     */
    public function resolveScopeKey(PlanScopeType $scopeType, ?int $userId, ?int $pipelineId, ?int $productGroupId): string
    {
        return match ($scopeType) {
            PlanScopeType::User => 'user:'.$userId,
            PlanScopeType::Pipeline => 'pipeline:'.$pipelineId,
            PlanScopeType::Company => $productGroupId !== null ? 'pg:'.$productGroupId : 'company',
        };
    }

    /**
     * Visibility-scoped user IDs eligible for a `scope_type=user` plan row —
     * the SAME population `buildMatrix()` expands into matrix rows (active
     * Manager/Director users, Own→self / Department→self+subtree / All→everyone).
     * Public so report aggregators (e.g. IncomeScheduleService's default
     * "все менеджеры" total) can sum the identical set of user-scoped cells
     * the matrix shows as its ИТОГО row — never a second, drifting definition.
     *
     * `is_service=false` excludes bot/service accounts from the plan-matrix
     * row population (audit fix, 2026-07-04) — mirrors
     * BestManagerService::wonDealAggregatesByOwner's standingIds filter and
     * SalesDashboardService's topManagers query so a service account never
     * appears as a plan-matrix row across any Sales aggregate.
     *
     * @return list<int>
     */
    public function scopedUserIds(User $viewer): array
    {
        $scope = $this->visibility->resolve($viewer);

        $usersQuery = User::query()
            ->where('is_active', true)
            ->where('is_service', false)
            ->role([Role::Manager->value, Role::Director->value]);

        if ($scope !== VisibilityScope::All) {
            $usersQuery->where(function ($q) use ($viewer, $scope): void {
                $q->where('id', $viewer->id);

                if ($scope === VisibilityScope::Department) {
                    $q->orWhereIn('department_id', $this->visibility->departmentSubtreeIds($viewer));
                }
            });
        }

        return $usersQuery->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function rowKeyFor(PlanTarget $target): string
    {
        return (string) match ($target->scope_type) {
            PlanScopeType::User => $target->scope_user_id,
            PlanScopeType::Pipeline => $target->scope_pipeline_id,
            PlanScopeType::Company => $target->scope_product_group_id ?? 0,
        };
    }

    /**
     * Resolve the visibility-scoped set of rows to expand for the matrix's
     * scope axis. scope_type=user → managers (Own/Department/All per role);
     * pipeline / company → not visibility-filtered (funnels/company total are
     * not personal data — every authed user may see the row list, matrix READ
     * access is governed by can_edit for writes only, contract §8.2).
     *
     * Public so the R4/R5 report aggregators (TaskMatrixService,
     * ConversionReportService) expand the IDENTICAL scope-axis population as
     * the P-1 matrix instead of re-deriving a second, potentially drifting
     * row list (reuse-gate, contract §5/§9).
     *
     * @return list<array{id: int, label: string}>
     */
    public function resolveScopeRows(PlanScopeType $scopeType, User $viewer): array
    {
        if ($scopeType === PlanScopeType::User) {
            return User::query()
                ->whereIn('id', $this->scopedUserIds($viewer))
                ->orderBy('full_name')
                ->get()
                ->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->full_name])
                ->values()
                ->all();
        }

        if ($scopeType === PlanScopeType::Pipeline) {
            return Pipeline::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($p): array => ['id' => $p->id, 'label' => $p->name])
                ->values()
                ->all();
        }

        // Company: a single synthetic row (id=0) — the whole-company total.
        return [['id' => 0, 'label' => 'MACRO Global']];
    }

    /**
     * @return list<array{key: string, label: string, period_month: int|null}>
     */
    private function buildColumns(): array
    {
        $labels = [
            1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр', 5 => 'Май', 6 => 'Июн',
            7 => 'Июл', 8 => 'Авг', 9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
        ];

        $columns = [];

        foreach ($labels as $month => $label) {
            $columns[] = ['key' => (string) $month, 'label' => $label, 'period_month' => $month];
        }

        $columns[] = ['key' => 'annual', 'label' => 'Год', 'period_month' => null];

        return $columns;
    }

    /**
     * ИТОГО row: sum across all scope rows, per column.
     *
     * @param  list<array{scope: array<string, mixed>, cells: array<string, array<string, mixed>>}>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildTotals(array $rows): array
    {
        $totals = [];

        foreach (array_merge(range(1, 12), ['annual']) as $key) {
            $key = (string) $key;
            $planSum = 0;
            $factSum = 0;

            foreach ($rows as $row) {
                $planSum += $row['cells'][$key]['plan_kopecks'] ?? 0;
                $factSum += $row['cells'][$key]['fact_kopecks'] ?? 0;
            }

            $pct = $this->kpiService->scorePct($factSum, $planSum);

            $totals[$key] = [
                'plan_kopecks' => $planSum,
                'fact_kopecks' => $factSum,
                'pct' => $pct,
                'badge' => $this->kpiService->scoreBadge($pct),
            ];
        }

        return $totals;
    }
}
