<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\ConversionReportFilters;
use App\Domain\Sales\Data\StageConversionFilters;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Services\ManagerKpiService;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ConversionReportService — R5 «Конверсии» (contract §6.8).
 *
 * Two blocks, both scope_type=user (the per-manager grid the sprint plan
 * asks for; `scope_type=pipeline` is validated by
 * PlanMetric::isCompatibleWithScope for a future FE pivot but this pass keeps
 * one axis, mirroring TaskMatrixService's R4 default):
 *
 *  - `custom`: user-authored pairs stored per-cell in plan_targets.config
 *    (contract §3.5 — numerator/denominator each {type: task|deal, kind|status}).
 *    Distinct pairs are identified by their config content — two cells with the
 *    SAME (numerator,denominator) pair across different scope rows/months are
 *    the SAME pair-row; grouping key = json_encode(config) sans the `unit` key.
 *  - `stage`: honest fact-only stage-to-stage conversion (our advantage over
 *    SpaceCRM's task-ratio-only conversions) — delegates entirely to
 *    StageConversionService (contract §2 "our improvement #3"), scoped to
 *    the SAME pipeline_id filter, no plan input.
 *
 * Reuse-gate: row/plan population via PlanTargetService::resolveScopeRows;
 * scorePct/scoreBadge via ManagerKpiService; stage fact via
 * StageConversionService (never re-derive deal_stage_history transitions here).
 */
class ConversionReportService
{
    public function __construct(
        private readonly PlanTargetService $planTargetService,
        private readonly ManagerKpiService $kpiService,
        private readonly StageConversionService $stageConversionService,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.8 shape
     */
    public function build(ConversionReportFilters $filters, User $viewer): array
    {
        $rowsMeta = $this->planTargetService->resolveScopeRows($filters->scopeType, $viewer);

        $cells = PlanTarget::query()
            ->where('metric', PlanMetric::Conversion->value)
            ->where('layer', $filters->layer->value)
            ->where('period_year', $filters->year)
            ->where('scope_type', $filters->scopeType->value)
            ->whereIn('scope_user_id', array_column($rowsMeta, 'id'))
            ->get();

        $custom = $this->buildCustomPairs($cells, $rowsMeta, $filters);

        $stage = $filters->pipelineId !== null
            ? $this->stageConversionService->build(
                StageConversionFilters::make($filters->pipelineId, $filters->year),
                $viewer,
            )['chain']
            : [];

        return [
            'meta' => [
                'year' => $filters->year,
                'layer' => $filters->layer->value,
                'pipeline_id' => $filters->pipelineId,
            ],
            'custom' => $custom,
            'stage' => $stage,
        ];
    }

    /**
     * @param  Collection<int, PlanTarget>  $cells
     * @param  list<array{id: int, label: string}>  $rowsMeta
     * @return list<array<string, mixed>>
     */
    private function buildCustomPairs(Collection $cells, array $rowsMeta, ConversionReportFilters $filters): array
    {
        // Group cells by their (numerator, denominator) pair identity — the
        // `unit` key is display metadata, not part of the pair's identity.
        $byPair = $cells->groupBy(function (PlanTarget $p): string {
            $config = $p->config ?? [];

            return json_encode([
                'numerator' => $config['numerator'] ?? null,
                'denominator' => $config['denominator'] ?? null,
            ]);
        });

        $pairs = [];

        foreach ($byPair as $pairCells) {
            /** @var Collection<int, PlanTarget> $pairCells */
            $first = $pairCells->first();
            $config = $first->config ?? [];
            $numerator = $config['numerator'] ?? null;
            $denominator = $config['denominator'] ?? null;

            if ($numerator === null || $denominator === null) {
                continue;
            }

            $columns = $this->buildColumns();
            $cellsOut = [];
            $annualPlanPct = null;
            $annualNum = 0;
            $annualDen = 0;

            // Each side is fetched ONCE for the whole year (one query per side,
            // grouped by month in SQL/PHP — was one query PER (side, month), i.e.
            // 24 queries per pair). Mirrors TaskMatrixService's year-filter/
            // group-by-month approach, which also sidesteps the sqlite
            // strftime zero-padding pitfall entirely (no month-literal WHERE).
            $userIds = array_column($rowsMeta, 'id');
            $numMonthly = $this->monthlyPairSideCounts($numerator, $filters->year, $userIds);
            $denMonthly = $this->monthlyPairSideCounts($denominator, $filters->year, $userIds);

            // scope_type=user for this pass — sum the pair's num/den counts
            // ACROSS every visibility-scoped row (company-wide conversion rate
            // for that pair) while the plan-% is read from ANY row's cell for
            // the pair (custom pairs are authored once, not per-manager, in
            // this pass's grid — the FE names the pair once and edits one row).
            foreach (range(1, 12) as $month) {
                $cellPlan = $pairCells->first(fn (PlanTarget $p): bool => (int) $p->period_month_key === $month);
                $planPct = $cellPlan?->value_count;

                $numCount = $numMonthly[$month] ?? 0;
                $denCount = $denMonthly[$month] ?? 0;
                $factPct = $denCount > 0 ? (int) round($numCount / $denCount * 100) : null;
                $pct = $planPct !== null ? $this->kpiService->scorePct($factPct ?? 0, $planPct) : null;

                $cellsOut[(string) $month] = [
                    'plan_pct' => $planPct,
                    'fact_pct' => $factPct,
                    'num_count' => $numCount,
                    'den_count' => $denCount,
                    'pct' => $pct,
                    'badge' => $this->kpiService->scoreBadge($pct),
                    'has_plan' => $cellPlan !== null,
                    'plan_id' => $cellPlan?->id,
                ];

                $annualNum += $numCount;
                $annualDen += $denCount;
            }

            $annualFactPct = $annualDen > 0 ? (int) round($annualNum / $annualDen * 100) : null;
            $annualCell = $pairCells->first(fn (PlanTarget $p): bool => $p->period_month === null);
            $annualPlanPct = $annualCell?->value_count;
            $annualPct = $annualPlanPct !== null ? $this->kpiService->scorePct($annualFactPct ?? 0, $annualPlanPct) : null;

            $cellsOut['annual'] = [
                'plan_pct' => $annualPlanPct,
                'fact_pct' => $annualFactPct,
                'num_count' => $annualNum,
                'den_count' => $annualDen,
                'pct' => $annualPct,
                'badge' => $this->kpiService->scoreBadge($annualPct),
                'has_plan' => $annualCell !== null,
                'plan_id' => $annualCell?->id,
            ];

            $pairs[] = [
                'plan_id' => $first->id,
                'name' => $this->pairName($numerator, $denominator),
                'numerator' => $numerator,
                'denominator' => $denominator,
                'columns' => $columns,
                'cells' => $cellsOut,
            ];
        }

        return $pairs;
    }

    /**
     * Count one side of a conversion pair for EVERY month of a year in one
     * batched GROUP BY query (was one query PER month — 12 calls per side, 24
     * per pair). {type:task, kind:X} = completed Activity count of that kind;
     * {type:deal, status:won} = won deals (effective recognition date, reusing
     * the SAME COALESCE convention as PlanTargetService/BestManagerService so a
     * won deal never lands in a different month here than everywhere else).
     *
     * Filters by YEAR only in SQL and groups by month in PHP after fetch — the
     * same safe pattern TaskMatrixService/PlanTargetService/BestManagerService
     * use, which sidesteps the sqlite strftime('%m', ...) zero-padding vs
     * pgsql EXTRACT(MONTH...) mismatch entirely (no month-literal WHERE here).
     *
     * @param  array<string, mixed>  $side
     * @param  list<int>  $userIds
     * @return array<int, int> month(1..12) => count
     */
    private function monthlyPairSideCounts(array $side, int $year, array $userIds): array
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        if (($side['type'] ?? null) === 'task') {
            $monthExpr = $isPg ? 'EXTRACT(MONTH FROM completed_at)' : "strftime('%m', completed_at)";
            $yearExpr = $isPg ? 'EXTRACT(YEAR FROM completed_at)' : "strftime('%Y', completed_at)";

            $rows = Activity::query()
                ->whereIn('responsible_id', $userIds)
                ->where('status', ActivityStatus::Done->value)
                ->whereNotNull('completed_at')
                ->where('kind', $side['kind'] ?? '')
                ->whereRaw($yearExpr.' = ?', [(string) $year])
                ->selectRaw($monthExpr.' as month, COUNT(*) as cnt')
                ->groupBy(DB::raw($monthExpr))
                ->get();

            return $this->monthlyMapFromRows($rows);
        }

        if (($side['type'] ?? null) === 'deal') {
            $effectiveDate = 'COALESCE(deals.closed_at, deals.signed_at, deals.paid_at, deals.stage_changed_at)';
            $yearExpr = $isPg ? 'EXTRACT(YEAR FROM '.$effectiveDate.')' : "strftime('%Y', {$effectiveDate})";
            $monthExpr = $isPg ? 'EXTRACT(MONTH FROM '.$effectiveDate.')' : "strftime('%m', {$effectiveDate})";

            $query = Deal::query()
                ->join('pipeline_stages as ps', 'deals.stage_id', '=', 'ps.id')
                ->whereIn('deals.owner_user_id', $userIds)
                ->whereNull('deals.archived_at')
                ->whereRaw($yearExpr.' = ?', [(string) $year]);

            match ($side['status'] ?? null) {
                'won' => $query->where('ps.is_won', true),
                'lost' => $query->where('ps.is_lost', true),
                default => null,
            };

            $rows = $query
                ->selectRaw($monthExpr.' as month, COUNT(*) as cnt')
                ->groupBy(DB::raw($monthExpr))
                ->get();

            return $this->monthlyMapFromRows($rows);
        }

        return [];
    }

    /**
     * @param  Collection<int, object{month: mixed, cnt: mixed}>  $rows
     * @return array<int, int> month(1..12) => count
     */
    private function monthlyMapFromRows(Collection $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->month] = (int) $row->cnt;
        }

        return $map;
    }

    private function pairName(array $numerator, array $denominator): string
    {
        return $this->sideLabel($numerator).' / '.$this->sideLabel($denominator);
    }

    /**
     * @param  array<string, mixed>  $side
     */
    private function sideLabel(array $side): string
    {
        if (($side['type'] ?? null) === 'task') {
            return match ($side['kind'] ?? null) {
                'call' => 'Звонок',
                'meeting' => 'Встреча',
                'presentation' => 'Презентация',
                'task' => 'Задача',
                'follow_up' => 'Возврат к клиенту',
                default => (string) ($side['kind'] ?? '?'),
            };
        }

        if (($side['type'] ?? null) === 'deal') {
            return match ($side['status'] ?? null) {
                'won' => 'Сделка',
                'lost' => 'Отказ',
                default => 'Сделка',
            };
        }

        return '?';
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
}
