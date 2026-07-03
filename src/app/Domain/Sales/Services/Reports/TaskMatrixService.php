<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\TaskMatrixFilters;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Services\ManagerKpiService;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TaskMatrixService — R4 «Закрытие задач» (contract §6.7).
 *
 * One matrix PER activity kind (call/meeting/task/follow_up/presentation —
 * ActivityType::taskLikeValues(), the single-sourced task-like axis; `note` is
 * documentation-only and never planned/counted here). Kind is stored in the
 * plan cell's own `config.activity_kind` (contract §3.5) — a manager's task
 * plan is authored once per (kind, scope, month), never a bare tasks_completed
 * cell without a kind. `config.activity_kind = "ftm"` is a sentinel that counts
 * ONLY FTM-qualified meetings (Activity::scopeFtmCounted) instead of raw
 * meeting completions, for the plan that specifically targets first-time
 * meetings.
 *
 * Reuse-gate (contract §5/§9): row population (scope=user → visibility-scoped
 * managers, scope=pipeline → funnels) delegates to
 * PlanTargetService::resolveScopeRows/scopedUserIds — the SAME population the
 * P-1 matrix expands, so R4 and the planning grid can never disagree on which
 * managers/pipelines are rows. scorePct/scoreBadge delegate to ManagerKpiService.
 *
 * Fact = COUNT of completed Activity rows (status=done AND completed_at NOT
 * NULL — ActivityService's single "completed" predicate) grouped by
 * kind × responsible_id × month. A pipeline-scoped row's fact is restricted to
 * activities whose target is a deal IN that pipeline (Activity carries no
 * pipeline_id of its own — a deal-linked activity's pipeline is resolved via
 * a join, mirroring ActivityService::openTasksCountForDeals' target_type=deal
 * + target_id IN (...) precedent, contract §8.3 read-join allowance).
 */
class TaskMatrixService
{
    public function __construct(
        private readonly PlanTargetService $planTargetService,
        private readonly ManagerKpiService $kpiService,
    ) {}

    /**
     * @return array<string, mixed> see contract §6.7 shape
     */
    public function build(TaskMatrixFilters $filters, User $viewer): array
    {
        $canEdit = $viewer->can('plans.manage');
        $kinds = $filters->kind !== null ? [$filters->kind] : ActivityType::taskLikeValues();

        // scope_type is fixed to `user` for R4 (the grid the sprint asked for is
        // per-manager); Pipeline is validated by PlanMetric::isCompatibleWithScope
        // and available to a future FE pivot, but this report's default axis is
        // the manager list — mirrors the P-1 default and the contract's example
        // payload (`rows` keyed by manager).
        $scopeType = PlanScopeType::User;
        $rowsMeta = $this->planTargetService->resolveScopeRows($scopeType, $viewer);

        // ---- Load ALL tasks_completed cells for this (layer, year) ONCE ----
        // config filtering happens in PHP (jsonb->>'activity_kind' portability
        // across pgsql/sqlite is not worth a driver-aware WHERE for a dataset
        // this small — same call as PlanTargetService::buildMatrix's own
        // single-query-then-groupBy approach).
        $allCells = PlanTarget::query()
            ->where('metric', PlanMetric::TasksCompleted->value)
            ->where('layer', $filters->layer->value)
            ->where('period_year', $filters->year)
            ->where('scope_type', $scopeType->value)
            ->whereIn('scope_user_id', array_column($rowsMeta, 'id'))
            ->get();

        $groups = [];

        foreach ($kinds as $kind) {
            $groups[] = $this->buildGroup($kind, $filters, $rowsMeta, $allCells, $viewer);
        }

        return [
            'meta' => [
                'year' => $filters->year,
                'layer' => $filters->layer->value,
                'value_kind' => 'count',
                'can_edit' => $canEdit,
            ],
            'groups' => $groups,
        ];
    }

    /**
     * @param  list<array{id: int, label: string}>  $rowsMeta
     * @param  Collection<int, PlanTarget>  $allCells
     * @return array<string, mixed>
     */
    private function buildGroup(string $kind, TaskMatrixFilters $filters, array $rowsMeta, Collection $allCells, User $viewer): array
    {
        $kindCells = $allCells->filter(
            fn (PlanTarget $p): bool => (string) ($p->config['activity_kind'] ?? null) === $kind,
        )->groupBy('scope_user_id');

        $columns = $this->buildColumns();
        $rows = [];

        foreach ($rowsMeta as $rowMeta) {
            $userId = $rowMeta['id'];
            /** @var Collection<int, PlanTarget> $userCells */
            $userCells = $kindCells->get($userId, collect());
            $monthlyFacts = $this->monthlyTaskFactFor($kind, $userId, $filters->year, $filters->pipelineId);

            $cells = [];
            $annualPlan = 0;
            $annualFact = 0;

            foreach (range(1, 12) as $month) {
                $cellPlan = $userCells->first(fn (PlanTarget $p): bool => (int) $p->period_month_key === $month);
                $plan = $cellPlan?->value_count ?? 0;
                $fact = $monthlyFacts[$month] ?? 0;
                $pct = $this->kpiService->scorePct($fact, $plan);

                $cells[(string) $month] = [
                    'plan_count' => $plan,
                    'fact_count' => $fact,
                    'pct' => $pct,
                    'badge' => $this->kpiService->scoreBadge($pct),
                    'has_plan' => $cellPlan !== null,
                    'plan_id' => $cellPlan?->id,
                ];

                $annualPlan += $plan;
                $annualFact += $fact;
            }

            $annualCell = $userCells->first(fn (PlanTarget $p): bool => $p->period_month === null);
            $annualPlanValue = $annualCell?->value_count ?? $annualPlan;
            $annualPct = $this->kpiService->scorePct($annualFact, $annualPlanValue);

            $cells['annual'] = [
                'plan_count' => $annualPlanValue,
                'fact_count' => $annualFact,
                'pct' => $annualPct,
                'badge' => $this->kpiService->scoreBadge($annualPct),
                'has_plan' => $annualCell !== null,
                'plan_id' => $annualCell?->id,
            ];

            $rows[] = [
                'scope' => ['type' => 'user', 'id' => $userId, 'label' => $rowMeta['label']],
                'cells' => $cells,
            ];
        }

        return [
            'kind' => $kind,
            'label' => $this->kindLabel($kind),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->buildTotals($rows),
        ];
    }

    /**
     * Completed-activity count per month for one user × kind × year, one
     * batched GROUP BY query (no N+1 across months). "Completed" mirrors
     * ActivityService's single predicate (status=done AND completed_at NOT
     * NULL); `kind="ftm"` is a sentinel routing through
     * Activity::scopeFtmCounted instead of a plain kind filter (contract §3.5).
     *
     * @return array<int, int> month(1..12) => fact count
     */
    private function monthlyTaskFactFor(string $kind, int $userId, int $year, ?int $pipelineId): array
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $monthExpr = $isPg ? 'EXTRACT(MONTH FROM completed_at)' : "strftime('%m', completed_at)";
        $yearExpr = $isPg ? 'EXTRACT(YEAR FROM completed_at)' : "strftime('%Y', completed_at)";

        $query = Activity::query()
            ->where('responsible_id', $userId)
            ->where('status', ActivityStatus::Done->value)
            ->whereNotNull('completed_at')
            ->whereRaw($yearExpr.' = ?', [(string) $year]);

        if ($kind === 'ftm') {
            $query->ftmCounted();
        } else {
            $query->where('kind', $kind);
        }

        if ($pipelineId !== null) {
            $dealIds = Deal::query()->where('pipeline_id', $pipelineId)->pluck('id');
            $query->where('target_type', ActivityTargetType::Deal->value)->whereIn('target_id', $dealIds);
        }

        $rows = $query
            ->selectRaw($monthExpr.' as month, COUNT(*) as cnt')
            ->groupBy(DB::raw($monthExpr))
            ->get();

        $totals = array_fill(1, 12, 0);

        foreach ($rows as $row) {
            $totals[(int) $row->month] = (int) $row->cnt;
        }

        return $totals;
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
                $planSum += $row['cells'][$key]['plan_count'] ?? 0;
                $factSum += $row['cells'][$key]['fact_count'] ?? 0;
            }

            $pct = $this->kpiService->scorePct($factSum, $planSum);

            $totals[$key] = [
                'plan_count' => $planSum,
                'fact_count' => $factSum,
                'pct' => $pct,
                'badge' => $this->kpiService->scoreBadge($pct),
            ];
        }

        return $totals;
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'call' => 'Звонки',
            'meeting' => 'Встречи',
            'task' => 'Задачи',
            'follow_up' => 'Возврат к клиенту',
            'presentation' => 'Презентации',
            'ftm' => 'Первые встречи (FTM)',
            default => $kind,
        };
    }
}
