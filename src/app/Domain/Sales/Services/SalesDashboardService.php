<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\DashboardFilters;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * SalesDashboardService — single aggregator for the S1.7 sales dashboard.
 *
 * All aggregations use GROUP BY in SQL (no N+1 PHP loops). Visibility scope is
 * applied to the base query before grouping so no forbidden deal ever leaks
 * into an aggregate (ARCHITECTURE.md §3 E6).
 *
 * Money: all amounts in kopecks (integer). Cross-currency conversion goes
 * through ExchangeRateService::convertAmount; if the rate is unavailable the
 * deal is skipped and multi_currency_warning is set to true.
 */
class SalesDashboardService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly ActivityService $activityService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * Build the complete dashboard payload (§В3).
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(DashboardFilters $filters, User $user): array
    {
        $scope = $this->visibility->resolve($user);

        // Resolve pipeline — HD4: default to first active sales pipeline.
        $pipeline = $this->resolvePipeline($filters->pipelineId);

        if ($pipeline === null) {
            return $this->emptyPayload($filters);
        }

        // Eager-load stages for re-use across aggregators.
        $pipeline->loadMissing('stages');

        // Base query: visibility-scoped, pipeline-scoped, period-scoped.
        $base = $this->baseQuery($pipeline->id, $scope, $user, $filters);

        $multiCurrencyWarning = false;

        $statusGroups = $this->statusGroups($pipeline->id, $scope, $user, $filters, $multiCurrencyWarning);
        $funnel = $this->funnelMetrics($pipeline, $base);
        $forecast = $this->forecastData($pipeline, $base, $multiCurrencyWarning);
        $topProducts = $this->topProducts($pipeline->id, $scope, $user, $filters, $multiCurrencyWarning);
        $topManagers = $this->topManagers($pipeline->id, $scope, $user, $filters, $multiCurrencyWarning);
        $dealsWithoutTasks = $this->activityService->countDealsWithoutTasks($pipeline->id, $user);

        return [
            'meta' => [
                'pipeline' => [
                    'id' => $pipeline->id,
                    'name' => $pipeline->name,
                    'kind' => $pipeline->kind?->value,
                ],
                'period' => [
                    'from' => $filters->dateFrom->toDateString(),
                    'to' => $filters->dateTo->toDateString(),
                ],
                'base_currency' => config('crm.currencies.default', 'RUB'),
                'multi_currency_warning' => $multiCurrencyWarning,
                'generated_at' => now()->toIso8601String(),
            ],
            'status_groups' => $statusGroups,
            'funnel' => $funnel,
            'forecast' => $forecast,
            'top_products' => $topProducts,
            'top_managers' => $topManagers,
            'deals_without_tasks' => [
                'count' => $dealsWithoutTasks,
                // Deep-link into the deals list pre-filtered to «без задач». The
                // param name MUST match the deals-list filter key (only_no_task);
                // the deals page reads pipeline_id + only_no_task from route.query.
                'filter_url' => "/deals?pipeline_id={$pipeline->id}&only_no_task=1",
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Status groups
    // -------------------------------------------------------------------------

    /**
     * 4 KPI groups: active / won / lost / total.
     * Trend = vs previous period.
     *
     * @return list<array<string, mixed>>
     */
    public function statusGroups(int $pipelineId, VisibilityScope $scope, User $user, DashboardFilters $filters, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');

        // baseQuery already joins pipeline_stages as «dps» (for the effective-date
        // period filter), so reuse that alias here instead of a second join.
        $rows = $this->baseQuery($pipelineId, $scope, $user, $filters)
            ->selectRaw(
                'CASE WHEN dps.is_won = true THEN \'won\' WHEN dps.is_lost = true THEN \'lost\' ELSE \'active\' END as grp,'.
                'COUNT(deals.id) as cnt,'.
                'SUM(deals.amount) as total_amount,'.
                'deals.currency as currency'
            )
            ->groupByRaw(
                'CASE WHEN dps.is_won = true THEN \'won\' WHEN dps.is_lost = true THEN \'lost\' ELSE \'active\' END, deals.currency'
            )
            ->get();

        /** @var array<string, array{count: int, amount: int}> $grouped */
        $grouped = [
            'active' => ['count' => 0, 'amount' => 0],
            'won' => ['count' => 0, 'amount' => 0],
            'lost' => ['count' => 0, 'amount' => 0],
        ];

        foreach ($rows as $row) {
            $grp = $row->grp;

            if (! isset($grouped[$grp])) {
                continue;
            }

            $converted = $this->safeConvert((int) $row->total_amount, $row->currency, $baseCurrency);

            if ($converted === null) {
                // Rate unavailable: skip BOTH count and amount so the card's count
                // and amount stay consistent (a deal whose money cannot be converted
                // must not be half-counted). The warning flag signals the omission.
                $multiCurrencyWarning = true;

                continue;
            }

            $grouped[$grp]['count'] += (int) $row->cnt;
            $grouped[$grp]['amount'] += $converted;
        }

        // Previous period for trend — built through the SAME scoped query as the
        // current period (SoftDeletes + visibility + manager_id) so numerator and
        // denominator measure the same population.
        $prevFilters = $filters->prevPeriod();
        $prevTrends = $this->computeTrendsFromPrev($pipelineId, $scope, $user, $prevFilters, $grouped);

        $total = [
            'count' => $grouped['active']['count'] + $grouped['won']['count'] + $grouped['lost']['count'],
            'amount' => $grouped['active']['amount'] + $grouped['won']['amount'] + $grouped['lost']['amount'],
        ];

        return [
            ['key' => 'active', 'label' => 'Активные',  'count' => $grouped['active']['count'], 'amount_kopecks' => $grouped['active']['amount'], 'trend_pct' => $prevTrends['active']],
            ['key' => 'won',    'label' => 'Выиграно',   'count' => $grouped['won']['count'],    'amount_kopecks' => $grouped['won']['amount'],    'trend_pct' => $prevTrends['won']],
            ['key' => 'lost',   'label' => 'Проиграно',  'count' => $grouped['lost']['count'],   'amount_kopecks' => $grouped['lost']['amount'],   'trend_pct' => $prevTrends['lost']],
            ['key' => 'total',  'label' => 'Итого',      'count' => $total['count'],             'amount_kopecks' => $total['amount'],             'trend_pct' => $prevTrends['total']],
        ];
    }

    // -------------------------------------------------------------------------
    // Funnel metrics (per-stage)
    // -------------------------------------------------------------------------

    /**
     * Per-stage funnel: count, avg_days, transition_to_next_pct.
     * Transition computed from the tail (accumulator) as in compute_funnel_metrics.
     *
     * @return array<string, mixed>
     */
    public function funnelMetrics(Pipeline $pipeline, Builder $base): array
    {
        $stages = $pipeline->stages;

        // Emit a driver-aware elapsed-seconds expression so the query works on
        // both PostgreSQL (production) and SQLite (test :memory: database).
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $elapsedExpr = $isPg
            ? 'EXTRACT(EPOCH FROM (NOW() - deals.stage_changed_at))'
            : '(julianday(\'now\') - julianday(deals.stage_changed_at)) * 86400';

        $rows = (clone $base)
            ->selectRaw(
                'deals.stage_id,'.
                'COUNT(deals.id) as cnt,'.
                'AVG(CASE WHEN deals.stage_changed_at IS NOT NULL '.
                    'THEN '.$elapsedExpr.' '.
                    'ELSE NULL END) as avg_seconds'
            )
            ->groupBy('deals.stage_id')
            ->get()
            ->keyBy('stage_id');

        $stageData = [];
        $totalActive = 0;
        $totalWon = 0;
        $totalLost = 0;

        foreach ($stages as $stage) {
            $row = $rows->get($stage->id);
            $cnt = $row ? (int) $row->cnt : 0;
            $avgSeconds = $row && $row->avg_seconds !== null ? (float) $row->avg_seconds : 0.0;
            $avgDays = $avgSeconds > 0 ? round($avgSeconds / 86400, 1) : 0.0;

            $stageData[] = [
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
                'sort_order' => $stage->sort_order,
                'count' => $cnt,
                'avg_days_in_stage' => $avgDays,
                'transition_to_next_pct' => 0.0, // filled in the tail-pass below
                'is_won' => $stage->is_won,
                'is_lost' => $stage->is_lost,
                'probability' => $this->probabilityForStage($stage->name),
            ];

            if ($stage->is_won) {
                $totalWon += $cnt;
            } elseif ($stage->is_lost) {
                $totalLost += $cnt;
            } else {
                $totalActive += $cnt;
            }
        }

        // Tail-pass: compute transition_to_next_pct from the back.
        $n = count($stageData);
        $laterTotal = 0;

        for ($i = $n - 1; $i >= 0; $i--) {
            $current = $stageData[$i]['count'];

            // No throughput to measure: the stage holds no deals of its own, so
            // there is nothing to compute a conversion FROM. Report null (JSON
            // null → «—» on the frontend) rather than a misleading 100 / 0.
            // This subsumes won/lost stages — their 100/0 semantics only hold
            // when the stage actually has deals. An empty stage with deals only
            // further downstream (a deal that jumped past it) still has no local
            // throughput, so it stays null — otherwise a single deal jumped to
            // "won" would paint every empty upstream stage 100%.
            if ($current === 0) {
                $stageData[$i]['transition_to_next_pct'] = null;

                continue;
            }

            if ($stageData[$i]['is_won']) {
                $stageData[$i]['transition_to_next_pct'] = 100.0;
                $laterTotal += $current;

                continue;
            }

            if ($stageData[$i]['is_lost']) {
                $stageData[$i]['transition_to_next_pct'] = 0.0;
                $laterTotal += $current;

                continue;
            }

            $denominator = $current + $laterTotal;

            $stageData[$i]['transition_to_next_pct'] = $denominator > 0
                ? round($laterTotal / $denominator * 100, 1)
                : null;

            $laterTotal += $current;
        }

        return [
            'stages' => $stageData,
            'total_active' => $totalActive,
            'total_won' => $totalWon,
            'total_lost' => $totalLost,
        ];
    }

    // -------------------------------------------------------------------------
    // Forecast
    // -------------------------------------------------------------------------

    /**
     * Weighted forecast per stage + HOT/Warm/Trial buckets.
     *
     * @return array<string, mixed>
     */
    public function forecastData(Pipeline $pipeline, Builder $base, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $hotThreshold = (float) config('crm.pipeline.hot_threshold', 0.7);

        $rows = (clone $base)
            ->selectRaw('deals.stage_id, SUM(deals.amount) as total_amount, deals.currency, COUNT(deals.id) as cnt')
            ->groupBy('deals.stage_id', 'deals.currency')
            ->get();

        $totalWeighted = 0;
        $hotKopecks = 0;
        $warmKopecks = 0;
        $trialKopecks = 0;

        /** @var array<int, array<string, mixed>> $byStage */
        $byStage = [];

        $stageMap = $pipeline->stages->keyBy('id');

        foreach ($rows as $row) {
            $stage = $stageMap->get($row->stage_id);

            if ($stage === null) {
                continue;
            }

            // Skip won/lost from forecast — already closed.
            if ($stage->is_won || $stage->is_lost) {
                continue;
            }

            $probability = $this->probabilityForStage($stage->name);
            $rawAmount = (int) $row->total_amount;
            $cnt = (int) $row->cnt;

            $converted = $this->safeConvert($rawAmount, $row->currency, $baseCurrency);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            $weighted = (int) round($converted * $probability);
            $totalWeighted += $weighted;

            if ($probability >= $hotThreshold) {
                $hotKopecks += $converted;
            } elseif ($probability >= 0.4) {
                $warmKopecks += $converted;
            } elseif ($probability >= 0.3) {
                $trialKopecks += $converted;
            }

            // Merge same stage_id entries from multiple currencies.
            if (isset($byStage[$stage->id])) {
                $byStage[$stage->id]['amount_kopecks'] += $converted;
                $byStage[$stage->id]['count'] += $cnt;
            } else {
                $byStage[$stage->id] = [
                    'stage_name' => $stage->name,
                    'amount_kopecks' => $converted,
                    'count' => $cnt,
                    'probability' => $probability,
                ];
            }
        }

        return [
            'total_weighted_kopecks' => $totalWeighted,
            'hot_kopecks' => $hotKopecks,
            'warm_kopecks' => $warmKopecks,
            'trial_kopecks' => $trialKopecks,
            'by_stage' => array_values($byStage),
        ];
    }

    // -------------------------------------------------------------------------
    // Top-N (chart-payload format per §В3)
    // -------------------------------------------------------------------------

    /**
     * Top-10 products by sum(deal_products.amount) — chart-payload.
     *
     * @return array<string, mixed>
     */
    public function topProducts(int $pipelineId, VisibilityScope $scope, User $user, DashboardFilters $filters, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');
        $baseIds = $this->baseQuery($pipelineId, $scope, $user, $filters)->select('deals.id');

        $rows = DB::table('deal_products as dp')
            ->join('catalog_products as cp', 'dp.product_id', '=', 'cp.id')
            ->whereIn('dp.deal_id', $baseIds)
            ->selectRaw('cp.name as product_name, SUM(dp.amount) as total_amount, COUNT(DISTINCT dp.deal_id) as deal_count, dp.currency')
            ->groupBy('cp.name', 'dp.currency')
            ->orderByRaw('SUM(dp.amount) DESC')
            ->limit(11)
            ->get();

        return $this->buildTopNChartPayload($rows, 'product_name', 10, $baseCurrency, $multiCurrencyWarning);
    }

    /**
     * Top-10 managers by sum(deals.amount) — chart-payload.
     *
     * @return array<string, mixed>
     */
    public function topManagers(int $pipelineId, VisibilityScope $scope, User $user, DashboardFilters $filters, bool &$multiCurrencyWarning): array
    {
        $baseCurrency = config('crm.currencies.default', 'RUB');

        // Group by users.id (the stable identity) — full_name is not UNIQUE, so
        // grouping on the name would collapse homonymous managers and merge their
        // revenue into one bar. Service accounts (e.g. the AMO import fallback)
        // are excluded; the display name is carried alongside the id.
        $rows = $this->baseQuery($pipelineId, $scope, $user, $filters)
            ->join('users as u', 'deals.owner_user_id', '=', 'u.id')
            ->where('u.is_service', false)
            ->selectRaw('u.id as manager_id, u.full_name as manager_name, SUM(deals.amount) as total_amount, COUNT(deals.id) as deal_count, deals.currency')
            ->groupBy('u.id', 'u.full_name', 'deals.currency')
            ->orderByRaw('SUM(deals.amount) DESC')
            ->limit(11)
            ->get();

        return $this->buildTopNChartPayload($rows, 'manager_name', 10, $baseCurrency, $multiCurrencyWarning, 'manager_id');
    }

    // -------------------------------------------------------------------------
    // Pure helpers (no DB — unit-testable)
    // -------------------------------------------------------------------------

    /**
     * Map a stage name to a forecast probability using keyword matching.
     * Case-insensitive substring; first config-order match wins; 0.1 default.
     *
     * Pure function — no DB.
     */
    public function probabilityForStage(string $stageName): float
    {
        /** @var array<string, float> $keywords */
        $keywords = config('crm.pipeline.probability_keywords', []);
        $lower = mb_strtolower($stageName);

        foreach ($keywords as $keyword => $probability) {
            if (str_contains($lower, (string) $keyword)) {
                return (float) $probability;
            }
        }

        return 0.1;
    }

    /**
     * Trend percentage: (current - previous) / previous × 100, rounded to 1dp.
     *
     * Returns null when there is not enough prior data to make the delta
     * meaningful. A prior count below the configured minimum
     * (`crm.kpi.min_prior_trend_count`) yields wild, misleading swings on sparse
     * data (e.g. previous=1, current=0 → −100%), so we report null → «—» /
     * «Недостаточно данных» on the frontend rather than a noisy number. This
     * also subsumes the previous = 0 (division-by-zero) guard.
     *
     * Pure function — no DB.
     */
    public function computeTrendPct(int $current, int $previous): ?float
    {
        $minPrior = (int) config('crm.kpi.min_prior_trend_count', 3);

        if ($previous < $minPrior) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    // -------------------------------------------------------------------------
    // Excel export
    // -------------------------------------------------------------------------

    /**
     * Build a PhpSpreadsheet xlsx export and return the raw bytes.
     *
     * Uses A1-notation setCellValue (PhpSpreadsheet v5 API — the old
     * setCellValueByColumnAndRow was removed in v2+).
     */
    public function buildXlsx(DashboardFilters $filters, User $user): string
    {
        $data = $this->getDashboardData($filters, $user);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Sales Dashboard')
            ->setCreator('MACRO Global CRM');

        // --- Sheet 1: Status Groups ---
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Groups');
        $sheet->freezePane('A2');

        $headers1 = ['Group', 'Count', 'Amount (kopecks)', 'Amount (RUB)', 'Trend %'];

        foreach ($headers1 as $col => $h) {
            $addr = $this->addr($col + 1, 1);
            $sheet->setCellValue($addr, $h);
            $sheet->getStyle($addr)->getFont()->setBold(true);
        }

        foreach ($data['status_groups'] as $ri => $row) {
            $r = $ri + 2;
            $sheet->setCellValue($this->addr(1, $r), $row['key']);
            $sheet->setCellValue($this->addr(2, $r), $row['count']);
            $sheet->setCellValue($this->addr(3, $r), $row['amount_kopecks']);
            $sheet->setCellValue($this->addr(4, $r), $row['amount_kopecks'] / 100);
            $sheet->setCellValue($this->addr(5, $r), $row['trend_pct']);
            $sheet->getStyle($this->addr(4, $r))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range(1, 5) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        // --- Sheet 2: Funnel ---
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Funnel');
        $sheet2->freezePane('A2');

        $headers2 = ['Stage', 'Count', 'Avg Days', 'Transition %', 'Probability'];

        foreach ($headers2 as $col => $h) {
            $addr = $this->addr($col + 1, 1);
            $sheet2->setCellValue($addr, $h);
            $sheet2->getStyle($addr)->getFont()->setBold(true);
        }

        foreach ($data['funnel']['stages'] as $ri => $row) {
            $r = $ri + 2;
            $sheet2->setCellValue($this->addr(1, $r), $row['stage_name']);
            $sheet2->setCellValue($this->addr(2, $r), $row['count']);
            $sheet2->setCellValue($this->addr(3, $r), $row['avg_days_in_stage']);
            $sheet2->setCellValue($this->addr(4, $r), $row['transition_to_next_pct']);
            $sheet2->setCellValue($this->addr(5, $r), $row['probability']);
        }

        foreach (range(1, 5) as $col) {
            $sheet2->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        // --- Sheet 3: Forecast ---
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Forecast');
        $sheet3->freezePane('A2');

        $headers3 = ['Metric', 'Amount (kopecks)', 'Amount (RUB)'];

        foreach ($headers3 as $col => $h) {
            $addr = $this->addr($col + 1, 1);
            $sheet3->setCellValue($addr, $h);
            $sheet3->getStyle($addr)->getFont()->setBold(true);
        }

        foreach ([
            ['Total Weighted', $data['forecast']['total_weighted_kopecks']],
            ['HOT', $data['forecast']['hot_kopecks']],
            ['Warm', $data['forecast']['warm_kopecks']],
            ['Trial', $data['forecast']['trial_kopecks']],
        ] as $ri => [$label, $amount]) {
            $r = $ri + 2;
            $sheet3->setCellValue($this->addr(1, $r), $label);
            $sheet3->setCellValue($this->addr(2, $r), $amount);
            $sheet3->setCellValue($this->addr(3, $r), $amount / 100);
            $sheet3->getStyle($this->addr(3, $r))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range(1, 3) as $col) {
            $sheet3->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Convert 1-based column + row to A1 notation (PhpSpreadsheet v5+).
     * Column 1 = A, Column 26 = Z, Column 27 = AA, etc.
     */
    private function addr(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col).$row;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolvePipeline(?int $pipelineId): ?Pipeline
    {
        if ($pipelineId !== null) {
            return Pipeline::query()->with('stages')->find($pipelineId);
        }

        return Pipeline::query()
            ->with('stages')
            ->sales()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Base Deal query: visibility + pipeline + period scoped.
     *
     * The period filter keys on the deal's EFFECTIVE recognition date, not on
     * stage_changed_at blindly: a won/lost deal counts in the period it was
     * actually closed (closed_at, then the signed/paid fact dates), falling back
     * to stage_changed_at when no fact date was captured (legacy / not-yet-filled
     * rows). Active deals always key on stage_changed_at. The pipeline_stages
     * join (alias «dps») provides the is_won/is_lost flags for the CASE and is
     * reused by statusGroups so no second join is needed.
     *
     * Eloquent's SoftDeletes global scope keeps deleted_at IS NULL applied
     * (Deal::query()), and the visibility match below scopes the population — both
     * are inherited by every aggregator and by the previous-period trend builder.
     *
     * @return Builder<Deal>
     */
    private function baseQuery(int $pipelineId, VisibilityScope $scope, User $user, DashboardFilters $filters): Builder
    {
        $effectiveDate = $this->effectiveDateExpr();

        $query = Deal::query()
            ->join('pipeline_stages as dps', 'deals.stage_id', '=', 'dps.id')
            ->where('deals.pipeline_id', $pipelineId)
            // Archived deals are hidden by default everywhere (DealService::applyFilters
            // convention); without this they would leak into every aggregate and the
            // previous-period trend.
            ->whereNull('deals.archived_at')
            ->whereRaw($effectiveDate.' >= ?', [$filters->dateFrom])
            ->whereRaw($effectiveDate.' <= ?', [$filters->dateTo]);

        if ($filters->managerId !== null) {
            $query->where('deals.owner_user_id', $filters->managerId);
        }

        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn(
                'deals.department_id',
                $this->visibility->departmentSubtreeIds($user)
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $user->id),
        };
    }

    /**
     * SQL expression for a deal's effective period-recognition date.
     *
     * - won/lost stage → COALESCE(closed_at, signed_at, paid_at, stage_changed_at)
     * - active stage   → stage_changed_at
     *
     * COALESCE keeps deals whose fact dates were never captured (the columns are
     * NULL on legacy rows) anchored to stage_changed_at instead of dropping them.
     * Uses the «dps» pipeline_stages join alias added by baseQuery.
     */
    private function effectiveDateExpr(): string
    {
        return 'CASE WHEN dps.is_won = true OR dps.is_lost = true '.
            'THEN COALESCE(deals.closed_at, deals.signed_at, deals.paid_at, deals.stage_changed_at) '.
            'ELSE deals.stage_changed_at END';
    }

    /**
     * Safe currency conversion: returns null if rate is unavailable (not base currency deal).
     */
    private function safeConvert(int $amountKopecks, string $fromCurrency, string $baseCurrency): ?int
    {
        if (strtoupper($fromCurrency) === strtoupper($baseCurrency)) {
            return $amountKopecks;
        }

        return $this->exchangeRateService->convertAmount($amountKopecks, $fromCurrency, $baseCurrency);
    }

    /**
     * Build chart-payload with optional «Другие» tail element.
     * Q5 pattern: rows already LIMIT 11; if count > limit → last element = «Другие».
     *
     * When $keyField is given, rows are aggregated by that stable identity
     * (e.g. users.id) instead of by the display name, so homonymous entities are
     * not merged. The display name from $nameKey is preserved for the label.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function buildTopNChartPayload(
        Collection $rows,
        string $nameKey,
        int $limit,
        string $baseCurrency,
        bool &$multiCurrencyWarning,
        ?string $keyField = null,
    ): array {
        /** @var array<string|int, array{name: string, amount: int}> $aggregated keyed by identity */
        $aggregated = [];

        foreach ($rows as $row) {
            $name = (string) $row->{$nameKey};
            $key = $keyField !== null ? $row->{$keyField} : $name;
            $rawAmount = (int) $row->total_amount;
            $currency = $row->currency ?? $baseCurrency;

            $converted = $this->safeConvert($rawAmount, $currency, $baseCurrency);

            if ($converted === null) {
                $multiCurrencyWarning = true;

                continue;
            }

            if (isset($aggregated[$key])) {
                $aggregated[$key]['amount'] += $converted;
            } else {
                $aggregated[$key] = ['name' => $name, 'amount' => $converted];
            }
        }

        // Sort DESC by amount.
        uasort($aggregated, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $labels = [];
        $data = [];
        $count = 0;
        $othersSum = 0;

        foreach ($aggregated as $entry) {
            $count++;

            if ($count <= $limit) {
                $labels[] = $entry['name'];
                $data[] = $entry['amount'];
            } else {
                $othersSum += $entry['amount'];
            }
        }

        if ($othersSum > 0) {
            $labels[] = 'Другие';
            $data[] = $othersSum;
        }

        return [
            'labels' => $labels,
            'datasets' => [['label' => 'Сумма, руб.', 'data' => $data]],
            'meta' => ['type' => 'bar', 'unit' => 'kopecks'],
        ];
    }

    /**
     * Compute trend_pct per group by running the previous-period scoped count.
     *
     * Routed through baseQuery() — identical SoftDeletes (deleted_at IS NULL),
     * visibility scope, manager_id filter and effective-date period boundaries as
     * the current period — so trend_pct compares like with like. The previous raw
     * DB::table('deals') bypassed every Eloquent scope and inflated the prior
     * period with soft-deleted / out-of-scope deals.
     *
     * @param  array<string, array{count: int, amount: int}>  $currentGrouped
     * @return array<string, ?float>
     */
    private function computeTrendsFromPrev(int $pipelineId, VisibilityScope $scope, User $user, DashboardFilters $prevFilters, array $currentGrouped): array
    {
        $prevRows = $this->baseQuery($pipelineId, $scope, $user, $prevFilters)
            ->selectRaw(
                'CASE WHEN dps.is_won = true THEN \'won\' WHEN dps.is_lost = true THEN \'lost\' ELSE \'active\' END as grp,'.
                'COUNT(deals.id) as cnt'
            )
            ->groupByRaw(
                'CASE WHEN dps.is_won = true THEN \'won\' WHEN dps.is_lost = true THEN \'lost\' ELSE \'active\' END'
            )
            ->get()
            ->keyBy('grp');

        $prev = [
            'active' => $prevRows->get('active') ? (int) $prevRows->get('active')->cnt : 0,
            'won' => $prevRows->get('won') ? (int) $prevRows->get('won')->cnt : 0,
            'lost' => $prevRows->get('lost') ? (int) $prevRows->get('lost')->cnt : 0,
        ];

        $prevTotal = $prev['active'] + $prev['won'] + $prev['lost'];
        $currentTotal = $currentGrouped['active']['count'] + $currentGrouped['won']['count'] + $currentGrouped['lost']['count'];

        return [
            'active' => $this->computeTrendPct($currentGrouped['active']['count'], $prev['active']),
            'won' => $this->computeTrendPct($currentGrouped['won']['count'], $prev['won']),
            'lost' => $this->computeTrendPct($currentGrouped['lost']['count'], $prev['lost']),
            'total' => $this->computeTrendPct($currentTotal, $prevTotal),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(DashboardFilters $filters): array
    {
        return [
            'meta' => [
                'pipeline' => null,
                'period' => [
                    'from' => $filters->dateFrom->toDateString(),
                    'to' => $filters->dateTo->toDateString(),
                ],
                'base_currency' => config('crm.currencies.default', 'RUB'),
                'multi_currency_warning' => false,
                'generated_at' => now()->toIso8601String(),
                'no_pipeline' => true,
            ],
            'status_groups' => [],
            'funnel' => ['stages' => [], 'total_active' => 0, 'total_won' => 0, 'total_lost' => 0],
            'forecast' => ['total_weighted_kopecks' => 0, 'hot_kopecks' => 0, 'warm_kopecks' => 0, 'trial_kopecks' => 0, 'by_stage' => []],
            'top_products' => ['labels' => [], 'datasets' => [], 'meta' => ['type' => 'bar', 'unit' => 'kopecks']],
            'top_managers' => ['labels' => [], 'datasets' => [], 'meta' => ['type' => 'bar', 'unit' => 'kopecks']],
            'deals_without_tasks' => ['count' => 0, 'filter_url' => '/deals?only_no_task=1'],
        ];
    }
}
