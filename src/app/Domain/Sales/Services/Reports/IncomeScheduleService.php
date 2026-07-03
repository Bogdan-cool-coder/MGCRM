<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\ReportFilters;
use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * IncomeScheduleService — R2 «График НП» day-calendar (contract §6.5).
 *
 * Four kopeck series per day (fact/expected/squeeze) + cumulative plan/fact,
 * ECharts-ready. Plan total for the month comes from plan_targets(new_income)
 * via PlanTargetService (reuse, never re-queried); fact = won-deal recognition
 * date (SalesDashboardService::effectiveDateExpr semantics — the deal's actual
 * closing date determines which day it lands on); expected/squeeze key on
 * expected_payment_date (contract §4.3 — the two are deliberately distinct).
 *
 * Cumulative plan distribution: linear across WORKING days only (contract §O9)
 * — weekends carry a 0 increment so the cumulative line does not "grow" on a
 * Saturday/Sunday when nothing was ever planned to land there.
 */
class IncomeScheduleService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly PlanTargetService $planTargetService,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.5 shape
     */
    public function build(ReportFilters $filters, User $viewer): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $multiCurrencyWarning = false;

        $daysInMonth = (int) $filters->monthStart->daysInMonth;

        $planTotalBaseKopecks = $this->resolvePlanTotal($filters, $viewer, $multiCurrencyWarning);

        $factByDay = $this->factByDay($filters, $viewer, $multiCurrencyWarning);
        $expectedByDay = $this->expectedByDay($filters, $viewer, $multiCurrencyWarning, squeeze: false);
        $squeezeByDay = $this->expectedByDay($filters, $viewer, $multiCurrencyWarning, squeeze: true);

        $workingDayCount = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            if (! $this->isWeekend($filters->year, $filters->month, $d)) {
                $workingDayCount++;
            }
        }

        $perWorkingDayPlan = $workingDayCount > 0 ? intdiv($planTotalBaseKopecks, $workingDayCount) : 0;
        // Remainder is absorbed into the LAST working day so cumulative_plan on
        // the final day of the month always equals plan_total exactly (integer
        // kopecks — no float drift, no "missing" kopeck at month end).
        $remainder = $workingDayCount > 0 ? $planTotalBaseKopecks - ($perWorkingDayPlan * $workingDayCount) : 0;

        $days = [];
        $cumulativePlan = 0;
        $cumulativeFact = 0;
        $workingDaySeen = 0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $isWeekend = $this->isWeekend($filters->year, $filters->month, $d);

            $planIncrement = 0;

            if (! $isWeekend) {
                $workingDaySeen++;
                $planIncrement = $perWorkingDayPlan + ($workingDaySeen === $workingDayCount ? $remainder : 0);
            }

            $cumulativePlan += $planIncrement;
            $factToday = $factByDay[$d] ?? 0;
            $cumulativeFact += $factToday;

            $days[] = [
                'day' => $d,
                'is_weekend' => $isWeekend,
                'fact_base_kopecks' => $factToday,
                'expected_base_kopecks' => $expectedByDay[$d] ?? 0,
                'squeeze_base_kopecks' => $squeezeByDay[$d] ?? 0,
                'cumulative_plan_base_kopecks' => $cumulativePlan,
                'cumulative_fact_base_kopecks' => $cumulativeFact,
            ];
        }

        return [
            'meta' => [
                'period' => [
                    'year' => $filters->year,
                    'month' => $filters->month,
                    'label' => $filters->monthLabel(),
                ],
                'days_in_month' => $daysInMonth,
                'base_currency' => $baseCurrency,
                'multi_currency_warning' => $multiCurrencyWarning,
            ],
            'plan_total_base_kopecks' => $planTotalBaseKopecks,
            'days' => $days,
        ];
    }

    /**
     * Plan total for the month (contract §6.5 `plan_total_base_kopecks`,
     * BUG-SCHEDULE-PLAN-ZERO fix, re-QA follow-up). Delegates cell reads to
     * PlanTargetService's scoping helpers — never re-derives the scoped-user
     * population or the FX conversion independently (contract §5 reuse-gate
     * keeps ONE definition).
     *
     * Scope resolution, in priority order:
     *  - `manager_id` filter → that single user's `scope_type=user` cell.
     *  - no `manager_id` (regardless of `pipeline_id`) → SUM of every
     *    visibility-scoped user's `scope_type=user` cell, in base currency,
     *    PLUS any additive `scope_type=pipeline`/`scope_type=company` cell.
     *
     * **Why `pipeline_id` does NOT switch to a single pipeline-scope lookup
     * (re-QA finding 2026-07-03):** the FE's default view always sends
     * `pipeline_id` (the funnel selector is pre-selected, never empty), so
     * treating `pipeline_id !== null` as "look up ONE pipeline cell instead of
     * summing users" silently reproduced BUG-SCHEDULE-PLAN-ZERO under the
     * real default request shape — the pipeline-scope cell is never written
     * by the matrix UI either. `plan_targets` `scope_type=user` cells carry NO
     * pipeline dimension (contract §2.1 — a user cell is just user × period),
     * so per-pipeline plan detail is not representable in the Ф1 data model
     * at all. Honest semantics: **the plan total is the same regardless of
     * which pipeline is selected** (only the FACT series is pipeline-filtered
     * via `factByDay`/`expectedByDay`'s own `pipeline_id` scoping) — a plan
     * is a company-wide/per-manager number, not a per-funnel one, until a
     * future phase adds a pipeline dimension to user-scoped cells. This
     * matches `PlanTargetService::buildMatrix()`, whose `scope_type=user` row
     * population is likewise NOT filtered by `pipeline_id` (its own docblock:
     * "pipeline / company — not visibility-filtered"), so the schedule's plan
     * total and the matrix's ИТОГО stay in agreement under a pipeline filter
     * too. No new `meta` flag needed — this is just consistent behaviour, not
     * a caveat the FE needs to react to.
     *
     * A standalone `scope_type=company` cell and a `scope_type=pipeline` cell
     * scoped to the requested `pipeline_id` (if either is ever authored —
     * nothing currently writes them from the UI) are ADDED on top of the
     * per-user sum, not used instead of it: these are orthogonal planning
     * levels (per-manager vs a company/funnel-wide top-line target) and the
     * contract does not ask for exactly-one-of semantics here. Flagged for
     * reviewer if product wants a stored pipeline/company cell to instead
     * override the per-user sum.
     */
    private function resolvePlanTotal(ReportFilters $filters, User $viewer, bool &$multiCurrencyWarning): int
    {
        if ($filters->userId !== null) {
            return $this->monthlyCellKopecksInBase(PlanScopeType::User, $filters->userId, $filters->year, $filters->month, $multiCurrencyWarning);
        }

        $userIds = $this->planTargetService->scopedUserIds($viewer);

        $total = 0;

        foreach ($userIds as $userId) {
            $total += $this->monthlyCellKopecksInBase(PlanScopeType::User, $userId, $filters->year, $filters->month, $multiCurrencyWarning);
        }

        if ($filters->pipelineId !== null) {
            $total += $this->monthlyCellKopecksInBase(PlanScopeType::Pipeline, $filters->pipelineId, $filters->year, $filters->month, $multiCurrencyWarning);
        }

        $total += $this->monthlyCellKopecksInBase(PlanScopeType::Company, null, $filters->year, $filters->month, $multiCurrencyWarning);

        return $total;
    }

    /**
     * One stored `new_income` monthly cell's value, converted to base currency
     * (contract §4.1 — same convention as `PlanTargetService::planKopecksInBase`).
     * A missing rate sets `multi_currency_warning` via the safeConvert fallback
     * already used for facts on this service, so plan and fact share the same
     * degrade path.
     */
    private function monthlyCellKopecksInBase(PlanScopeType $scopeType, ?int $scopeId, int $year, int $month, bool &$multiCurrencyWarning): int
    {
        $scopeKey = $this->planTargetService->resolveScopeKey($scopeType, $scopeType === PlanScopeType::User ? $scopeId : null, $scopeType === PlanScopeType::Pipeline ? $scopeId : null, null);

        $cell = PlanTarget::query()
            ->where('metric', PlanMetric::NewIncome->value)
            ->where('layer', PlanLayer::Operative->value)
            ->where('scope_key', $scopeKey)
            ->where('period_year', $year)
            ->where('period_month_key', $month)
            ->first();

        if ($cell === null) {
            return 0;
        }

        $baseCurrency = config('crm.currencies.default', 'RUB');

        return $this->safeConvert((int) $cell->value_kopecks, (string) ($cell->currency ?? $baseCurrency), $baseCurrency, $multiCurrencyWarning);
    }

    /**
     * Won-deal fact grouped by day-of-month, using the SAME effective
     * recognition date semantics as SalesDashboardService::effectiveDateExpr
     * (closed_at → signed_at → paid_at → stage_changed_at fallback chain).
     *
     * @return array<int, int> day(1..N) => fact kopecks in base currency
     */
    private function factByDay(ReportFilters $filters, User $viewer, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $scope = $this->visibility->resolve($viewer);

        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $effectiveDate = 'COALESCE(deals.closed_at, deals.signed_at, deals.paid_at, deals.stage_changed_at)';
        $dayExpr = $isPg ? "EXTRACT(DAY FROM {$effectiveDate})" : "CAST(strftime('%d', {$effectiveDate}) AS INTEGER)";

        $query = Deal::query()
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->where('ps.is_won', true)
            ->whereNull('deals.archived_at')
            ->whereRaw("{$effectiveDate} >= ?", [$filters->monthStart])
            ->whereRaw("{$effectiveDate} <= ?", [$filters->monthEnd]);

        $query = $this->applyDealScope($query, $scope, $viewer);

        if ($filters->pipelineId !== null) {
            $query->where('deals.pipeline_id', $filters->pipelineId);
        }

        if ($filters->userId !== null) {
            $query->where('deals.owner_user_id', $filters->userId);
        }

        $rows = $query
            ->selectRaw("{$dayExpr} as day, SUM(deals.amount) as total_amount, deals.currency")
            ->groupBy(DB::raw($dayExpr), 'deals.currency')
            ->get();

        return $this->accumulateByDay($rows, $baseCurrency, $multiCurrencyWarning);
    }

    /**
     * Open-deal expected/squeeze amounts grouped by day-of-month, keyed on
     * expected_payment_date (contract §4.3 — distinct from recognition date).
     *
     * @return array<int, int> day(1..N) => kopecks in base currency
     */
    private function expectedByDay(ReportFilters $filters, User $viewer, bool &$multiCurrencyWarning, bool $squeeze): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $scope = $this->visibility->resolve($viewer);

        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $dayExpr = $isPg ? 'EXTRACT(DAY FROM deals.expected_payment_date)' : "CAST(strftime('%d', deals.expected_payment_date) AS INTEGER)";

        $query = Deal::query()
            ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
            ->where('ps.is_won', false)
            ->where('ps.is_lost', false)
            ->whereNull('deals.archived_at')
            ->whereNotNull('deals.expected_payment_date');

        if ($squeeze) {
            // «Дожим» rolled onto TODAY (contract §6.5 legend: squeeze_base_kopecks
            // = overdue expected rolled onto today) — only meaningful for the
            // current day of the queried month, and only if that day is today.
            $today = now();

            if (! $today->isSameMonth($filters->monthStart) || $today->year !== $filters->year) {
                return [];
            }

            $query->where('deals.expected_payment_date', '<', $today->toDateString())
                ->whereNull('deals.paid_at');
        } else {
            $query->whereBetween('deals.expected_payment_date', [$filters->monthStart->toDateString(), $filters->monthEnd->toDateString()]);
        }

        $query = $this->applyDealScope($query, $scope, $viewer);

        if ($filters->pipelineId !== null) {
            $query->where('deals.pipeline_id', $filters->pipelineId);
        }

        if ($filters->userId !== null) {
            $query->where('deals.owner_user_id', $filters->userId);
        }

        if ($squeeze) {
            // Squeeze totals roll onto today's day-of-month bucket regardless of
            // each deal's own (past) expected_payment_date.
            $todayDay = (int) now()->day;

            $rows = $query
                ->selectRaw('SUM(deals.amount) as total_amount, deals.currency')
                ->groupBy('deals.currency')
                ->get();

            $total = 0;

            foreach ($rows as $row) {
                $total += $this->safeConvert((int) $row->total_amount, (string) $row->currency, $baseCurrency, $multiCurrencyWarning);
            }

            return $total > 0 ? [$todayDay => $total] : [];
        }

        $rows = $query
            ->selectRaw("{$dayExpr} as day, SUM(deals.amount) as total_amount, deals.currency")
            ->groupBy(DB::raw($dayExpr), 'deals.currency')
            ->get();

        return $this->accumulateByDay($rows, $baseCurrency, $multiCurrencyWarning);
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    private function applyDealScope(Builder $query, VisibilityScope $scope, User $viewer): Builder
    {
        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->where(
                fn (Builder $q): Builder => $q
                    ->where('deals.owner_user_id', $viewer->id)
                    ->orWhereIn('deals.department_id', $this->visibility->departmentSubtreeIds($viewer)),
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $viewer->id),
        };
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    private function accumulateByDay(Collection $rows, string $baseCurrency, bool &$multiCurrencyWarning): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $day = (int) $row->day;
            $amount = (int) ($row->total_amount ?? 0);
            $currency = (string) ($row->currency ?? $baseCurrency);

            $converted = $this->safeConvert($amount, $currency, $baseCurrency, $multiCurrencyWarning);
            $totals[$day] = ($totals[$day] ?? 0) + $converted;
        }

        return $totals;
    }

    private function safeConvert(int $amountKopecks, string $fromCurrency, string $toCurrency, bool &$multiCurrencyWarning): int
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return $amountKopecks;
        }

        $converted = $this->exchangeRateService->convertAmount($amountKopecks, $fromCurrency, $toCurrency);

        if ($converted === null) {
            $multiCurrencyWarning = true;

            return $amountKopecks;
        }

        return $converted;
    }

    private function isWeekend(int $year, int $month, int $day): bool
    {
        return Carbon::create($year, $month, $day)->isWeekend();
    }
}
