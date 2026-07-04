<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\ProductIncomeFilters;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Services\DealAmountCalculator;
use App\Domain\Sales\Services\ManagerKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ProductIncomeService — R6 «Поступления по линейкам» (contract §6.9).
 *
 * Rows = `catalog_product_groups` (the продуктовая линейка axis, contract
 * §1.3 anchor decision — NOT a new taxonomy). Per (line × month):
 *  - plan: plan_targets(metric=product_income, scope_type=company,
 *    scope_product_group_id=line) — always company-wide-per-line (contract
 *    §3.3), never per-manager for this sprint.
 *  - fact: interim won_deals money-fact (contract §4.2), attributed to a
 *    line via deal_products.product_id → catalog_products.group_id, summed
 *    from deal_products.amount (the NET per-line-item kopecks, already net of
 *    each line's OWN per-line discount — contract §8.3 read-join allowance)
 *    in the line-item's OWN currency, converted at the deal's
 *    effective-recognition month (COALESCE(closed_at, signed_at, paid_at,
 *    stage_changed_at), the same convention as
 *    SalesDashboardService::effectiveDateExpr /
 *    PlanTargetService::monthlyMoneyFactFor — reuse-gate, never re-derive
 *    which month a won deal's revenue lands in).
 *
 *    The deal-LEVEL discount_percent (DealAmountCalculator, applied on top of
 *    the per-line discount to derive `deals.amount`) is folded in here too
 *    (audit fix, 2026-07-04): summing raw deal_products.amount ignored it,
 *    so a 10%-discounted deal's product-income rows overstated that deal's
 *    contribution by the discount amount even though `deals.amount` (and
 *    every other Sales aggregate) already reflects the discount. The percent
 *    is applied per (deal, group, month, currency) subtotal — via
 *    DealAmountCalculator::applyPercent, the SAME calculator/rounding
 *    DealService::recalcAmount uses — before folding into the report total,
 *    so a single-line deal matches `deals.amount` exactly; a multi-line deal
 *    with lines split across groups/months may round by at most a few
 *    kopecks per bucket versus a strict per-line application (documented,
 *    negligible — no per-line group/month attribution exists to do better).
 *  - expected: open deals (not won/lost) with expected_payment_date in the
 *    month, same line-item attribution — contract §6.9 "expected = open
 *    deals expected_payment_date in month by line".
 *  - total = fact + expected (contract §6.9 "«Всего»"); total_pct =
 *    total/plan; fact_pct = fact/plan — scored via ManagerKpiService's
 *    scorePct/scoreBadge (reuse-gate, never re-derived).
 *
 * Read-scope: visibility-scoped via VisibilityResolver over the underlying
 * deals (Own/Department/All), mirroring ExpectedIncomeRegistryService /
 * BestManagerService — the report never leaks a forbidden deal's revenue
 * into a line total even though the ROWS themselves (product lines) are not
 * personal data (contract §8.2: reads are visibility-scoped, not gated).
 */
class ProductIncomeService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ManagerKpiService $kpiService,
        private readonly DealAmountCalculator $amountCalculator,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.9 shape
     */
    public function build(ProductIncomeFilters $filters, User $viewer): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $canEdit = $viewer->can('plans.manage');
        $multiCurrencyWarning = false;

        $groups = ProductGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $plans = PlanTarget::query()
            ->where('metric', PlanMetric::ProductIncome->value)
            ->where('layer', $filters->layer->value)
            ->where('period_year', $filters->year)
            ->where('scope_type', PlanScopeType::Company->value)
            ->whereIn('scope_product_group_id', $groups->pluck('id'))
            ->get()
            ->groupBy('scope_product_group_id');

        $factByGroup = $this->monthlyFactByGroup($filters, $viewer, $baseCurrency, $multiCurrencyWarning, won: true);
        $expectedByGroup = $this->monthlyFactByGroup($filters, $viewer, $baseCurrency, $multiCurrencyWarning, won: false);

        $columns = $this->buildColumns();
        $rows = [];

        foreach ($groups as $group) {
            $groupPlans = $plans->get($group->id, collect());
            $monthlyFact = $factByGroup[$group->id] ?? array_fill(1, 12, 0);
            $monthlyExpected = $expectedByGroup[$group->id] ?? array_fill(1, 12, 0);

            $cells = [];
            $annualPlan = 0;
            $annualFact = 0;
            $annualExpected = 0;

            foreach (range(1, 12) as $month) {
                $cellPlan = $groupPlans->first(fn (PlanTarget $p): bool => (int) $p->period_month_key === $month);
                $rawPlan = $cellPlan?->value_kopecks ?? 0;
                $plan = $cellPlan !== null
                    ? $this->planKopecksInBase($rawPlan, $cellPlan->currency, $baseCurrency, $filters->year, $month, $multiCurrencyWarning)
                    : 0;
                $fact = $monthlyFact[$month] ?? 0;
                $expected = $monthlyExpected[$month] ?? 0;
                $total = $fact + $expected;

                $totalPct = $this->kpiService->scorePct($total, $plan);
                $factPct = $this->kpiService->scorePct($fact, $plan);

                $cells[(string) $month] = [
                    'plan_kopecks' => $plan,
                    'expected_kopecks' => $expected,
                    'fact_kopecks' => $fact,
                    'total_kopecks' => $total,
                    'total_pct' => $totalPct,
                    'fact_pct' => $factPct,
                    'badge' => $this->kpiService->scoreBadge($totalPct),
                    'currency' => $cellPlan?->currency ?? $baseCurrency,
                    'has_plan' => $cellPlan !== null,
                    'plan_id' => $cellPlan?->id,
                ];

                $annualPlan += $plan;
                $annualFact += $fact;
                $annualExpected += $expected;
            }

            $annualCell = $groupPlans->first(fn (PlanTarget $p): bool => $p->period_month === null);

            if ($annualCell !== null) {
                $annualPlan = $this->planKopecksInBase($annualCell->value_kopecks ?? 0, $annualCell->currency, $baseCurrency, $filters->year, 1, $multiCurrencyWarning);
            }

            $annualTotal = $annualFact + $annualExpected;
            $annualTotalPct = $this->kpiService->scorePct($annualTotal, $annualPlan);
            $annualFactPct = $this->kpiService->scorePct($annualFact, $annualPlan);

            $cells['annual'] = [
                'plan_kopecks' => $annualPlan,
                'expected_kopecks' => $annualExpected,
                'fact_kopecks' => $annualFact,
                'total_kopecks' => $annualTotal,
                'total_pct' => $annualTotalPct,
                'fact_pct' => $annualFactPct,
                'badge' => $this->kpiService->scoreBadge($annualTotalPct),
                'currency' => $annualCell?->currency ?? $baseCurrency,
                'has_plan' => $annualCell !== null,
                'plan_id' => $annualCell?->id,
            ];

            $rows[] = [
                'scope' => ['type' => 'product_group', 'id' => $group->id, 'label' => $group->name],
                'cells' => $cells,
            ];
        }

        return [
            'meta' => [
                'year' => $filters->year,
                'layer' => $filters->layer->value,
                'base_currency' => $baseCurrency,
                'fact_source' => config('crm.plans.fact_source', 'won_deals'),
                'multi_currency_warning' => $multiCurrencyWarning,
                'can_edit' => $canEdit,
            ],
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->buildTotals($rows),
        ];
    }

    /**
     * Money attributed to a product line, grouped by month, for either the
     * WON-deal fact or the OPEN-deal expected series, one batched query (no
     * N+1 across lines or months). Visibility-scoped over the underlying
     * deals so a Department/Own-scoped viewer's report total can never
     * include a forbidden deal's revenue (contract §8.2).
     *
     * @return array<int, array<int, int>> group_id => month(1..12) => kopecks (base currency)
     */
    private function monthlyFactByGroup(ProductIncomeFilters $filters, User $viewer, string $baseCurrency, bool &$multiCurrencyWarning, bool $won): array
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        $dateExpr = $won
            ? 'COALESCE(deals.closed_at, deals.signed_at, deals.paid_at, deals.stage_changed_at)'
            : 'deals.expected_payment_date';

        $yearExpr = $isPg ? 'EXTRACT(YEAR FROM '.$dateExpr.')' : "strftime('%Y', {$dateExpr})";
        $monthExpr = $isPg ? 'EXTRACT(MONTH FROM '.$dateExpr.')' : "strftime('%m', {$dateExpr})";

        $query = DB::table('deal_products as dp')
            ->join('catalog_products as cp', 'dp.product_id', '=', 'cp.id')
            ->join('deals', 'dp.deal_id', '=', 'deals.id')
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->whereNotNull('cp.group_id')
            ->whereNull('deals.deleted_at')
            ->whereNull('deals.archived_at')
            ->whereRaw($dateExpr.' IS NOT NULL')
            ->whereRaw($yearExpr.' = ?', [(string) $filters->year]);

        $query = $won
            ? $query->where('ps.is_won', true)
            : $query->where('ps.is_won', false)->where('ps.is_lost', false);

        if ($filters->pipelineId !== null) {
            $query->where('deals.pipeline_id', $filters->pipelineId);
        }

        $scope = $this->visibility->resolve($viewer);

        match ($scope) {
            VisibilityScope::All => null,
            VisibilityScope::Department => $query->where(
                fn ($q) => $q
                    ->where('deals.owner_user_id', $viewer->id)
                    ->orWhereIn('deals.department_id', $this->visibility->departmentSubtreeIds($viewer)),
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $viewer->id),
        };

        // Grouped by deal_id too (not just group/month/currency) so the
        // deal-level discount_percent can be applied to EACH deal's own
        // subtotal before folding into the report total — a shared group/month
        // bucket must not apply one deal's discount to another deal's lines.
        $rows = $query
            ->selectRaw(
                'dp.deal_id as deal_id, deals.discount_percent as discount_percent, cp.group_id as group_id, '.$monthExpr.' as month, dp.currency as currency, SUM(dp.amount) as total_amount',
            )
            ->groupBy('dp.deal_id', 'deals.discount_percent', 'cp.group_id', DB::raw($monthExpr), 'dp.currency')
            ->get();

        /** @var array<int, array<int, int>> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $groupId = (int) $row->group_id;
            $month = (int) $row->month;
            $currency = (string) ($row->currency ?? $baseCurrency);
            $discountPercent = (int) ($row->discount_percent ?? 0);
            $amount = $this->amountCalculator->applyPercent((int) ($row->total_amount ?? 0), $discountPercent);

            $totals[$groupId] ??= array_fill(1, 12, 0);

            if (strtoupper($currency) === strtoupper($baseCurrency)) {
                $totals[$groupId][$month] += $amount;

                continue;
            }

            $ratesDate = Carbon::create($filters->year, $month, 1)->toDateString();
            $converted = $this->exchangeRateService->convertAmount($amount, $currency, $baseCurrency, $ratesDate);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $totals[$groupId][$month] += $converted;
        }

        return $totals;
    }

    /**
     * Convert a stored plan cell's kopecks to base currency (contract §4.1) —
     * same 1st-of-month rate convention as PlanTargetService::planKopecksInBase,
     * duplicated here (private helper, not cross-service) because the report
     * lives in a different aggregator; the rate resolution rule itself is
     * copied verbatim, not re-derived.
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
     * ИТОГО row: sum across all product-line rows, per column.
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
            $expectedSum = 0;

            foreach ($rows as $row) {
                $planSum += $row['cells'][$key]['plan_kopecks'] ?? 0;
                $factSum += $row['cells'][$key]['fact_kopecks'] ?? 0;
                $expectedSum += $row['cells'][$key]['expected_kopecks'] ?? 0;
            }

            $totalSum = $factSum + $expectedSum;
            $totalPct = $this->kpiService->scorePct($totalSum, $planSum);

            $totals[$key] = [
                'plan_kopecks' => $planSum,
                'expected_kopecks' => $expectedSum,
                'fact_kopecks' => $factSum,
                'total_kopecks' => $totalSum,
                'total_pct' => $totalPct,
                'badge' => $this->kpiService->scoreBadge($totalPct),
            ];
        }

        return $totals;
    }
}
