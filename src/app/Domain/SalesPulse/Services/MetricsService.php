<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseMetrics;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use Illuminate\Support\Collection;

/**
 * MetricsService — the six /finishday metrics (port of the AMO bot's metrics.py,
 * spec §1.2). Pure computation over two snapshots; the only DB touch is loading
 * the PipelineStage rows referenced by the snapshots (to classify a deal's
 * morning→evening stage move via StageClassificationService). Stages are loaded
 * once and memoised for the call.
 *
 *   1. Активность   done/total     — total = plan task_ids; done = plan tasks that
 *                                    exist AND are completed in the evening snapshot.
 *   2. Update статуса updates/companies — companies = unique deals in plan; updates
 *                                    = deals moved FORWARD over the funnel.
 *   3. Пропущено      missed         — a plan task not done in the evening AND its
 *                                    deal has NO note today; a vanished plan task is
 *                                    also missed.
 *   4. Внеплановые     extra          — |fact_task_ids − plan_task_ids| (fact = done
 *                                    in the evening snapshot).
 *   5. Downgrade       downgrades     — deals moved BACKWARD over the funnel (incl.
 *                                    into cold; lost excluded — counted as Lost).
 *   6. Lost            losts          — deals moved INTO lost today (after=lost,
 *                                    before≠lost).
 *
 * Status classification (spec §1.2, per deal, mutually exclusive, only when
 * before≠after): lost → forward → downgrade, in that order.
 */
class MetricsService
{
    public function __construct(
        private readonly StageClassificationService $classifier,
    ) {}

    /**
     * @var array<int, PipelineStage|null> Memoised stage_id => stage (null = absent).
     */
    private array $stageCache = [];

    /**
     * Compute the six metrics (spec §1.2).
     *
     * @param  DaySnapshot|null  $morningPlan  Morning PLAN snapshot (null = no plan was fixed).
     * @param  DaySnapshot  $eveningSnap  Fresh evening collect_day.
     * @param  array<int, true>  $dealIdsWithNotesToday  deal_id => true membership set.
     */
    public function compute(?DaySnapshot $morningPlan, DaySnapshot $eveningSnap, array $dealIdsWithNotesToday): PulseMetrics
    {
        // The "plan" the metrics evaluate against is the MORNING plan when present,
        // otherwise there is no plan to measure (everything is extra) — the bot
        // treats a missing morning plan as an empty plan (spec §1.2 / §7 fact-render).
        $planRows = $morningPlan?->plan ?? [];

        $this->primeStageCache($morningPlan, $eveningSnap);

        // Evening lookup tables.
        $eveningByTaskId = $this->indexByTaskId($eveningSnap->plan);
        $factTaskIds = $this->doneTaskIds($eveningSnap->plan);

        $planTaskIds = $this->taskIds($planRows);

        // --- Metric 1: Активность (done/total) ---
        $total = count($planTaskIds);
        $done = 0;
        foreach ($planTaskIds as $taskId) {
            $eveningRow = $eveningByTaskId[$taskId] ?? null;
            if ($eveningRow !== null && $eveningRow->isCompleted) {
                $done++;
            }
        }

        // --- Metric 3: Пропущено (missed) ---
        $missed = 0;
        foreach ($planRows as $planRow) {
            $eveningRow = $eveningByTaskId[$planRow->taskId] ?? null;

            // Done in the evening → not missed.
            if ($eveningRow !== null && $eveningRow->isCompleted) {
                continue;
            }

            // Vanished plan task → missed.
            if ($eveningRow === null) {
                $missed++;

                continue;
            }

            // Still open: missed only when the deal has no note today.
            $dealId = $planRow->dealId;
            if ($dealId === null || ! isset($dealIdsWithNotesToday[$dealId])) {
                $missed++;
            }
        }

        // --- Metric 4: Внеплановые (extra) ---
        // |fact_task_ids − plan_task_ids| (spec §1.2).
        $planTaskIdSet = array_fill_keys($planTaskIds, true);
        $extra = 0;
        foreach ($factTaskIds as $taskId) {
            if (! isset($planTaskIdSet[$taskId])) {
                $extra++;
            }
        }

        // --- Metrics 2/5/6: per-deal status classification ---
        // companies = unique deals in the plan (spec §1.2 metric 2).
        $companies = $this->uniqueDealIds($planRows);

        $statusUpdates = 0;
        $statusDowngrades = 0;
        $losts = 0;

        foreach ($companies as $dealId) {
            $beforeStatusId = $this->morningStatusId($morningPlan, $dealId);
            $afterStatusId = $this->eveningStatusId($eveningSnap, $dealId);

            if ($beforeStatusId === $afterStatusId) {
                continue; // No move (spec §1.2 — classify only when before≠after).
            }

            $before = $this->stage($beforeStatusId);
            $after = $this->stage($afterStatusId);

            // Mutually exclusive cascade (spec §1.2): lost → forward → downgrade.
            if ($this->classifier->isLost($after) && ! $this->classifier->isLost($before)) {
                $losts++;
            } elseif ($this->classifier->isForwardMove($before, $after)) {
                $statusUpdates++;
            } elseif ($this->classifier->isFunnelDowngrade($before, $after)) {
                $statusDowngrades++;
            }
            // else: ignore (e.g. unknown↔unknown).
        }

        return new PulseMetrics(
            activityDone: $done,
            activityTotal: $total,
            statusUpdates: $statusUpdates,
            companies: count($companies),
            missed: $missed,
            extraTasks: $extra,
            statusDowngrades: $statusDowngrades,
            losts: $losts,
        );
    }

    /**
     * Load every PipelineStage referenced by either snapshot's leads_by_id once.
     */
    private function primeStageCache(?DaySnapshot $morningPlan, DaySnapshot $eveningSnap): void
    {
        $ids = [];

        foreach ([$morningPlan, $eveningSnap] as $snap) {
            if ($snap === null) {
                continue;
            }
            foreach ($snap->leadsById as $lead) {
                $statusId = $lead['status_id'] ?? null;
                if ($statusId !== null) {
                    $ids[(int) $statusId] = true;
                }
            }
        }

        $missing = array_values(array_filter(
            array_keys($ids),
            fn (int $id): bool => ! array_key_exists($id, $this->stageCache),
        ));

        if ($missing === []) {
            return;
        }

        /** @var Collection<int, PipelineStage> $stages */
        $stages = PipelineStage::query()->whereIn('id', $missing)->get();
        $byId = $stages->keyBy('id');

        foreach ($missing as $id) {
            $this->stageCache[$id] = $byId->get($id);
        }
    }

    private function stage(?int $stageId): ?PipelineStage
    {
        if ($stageId === null) {
            return null;
        }

        return $this->stageCache[$stageId] ?? null;
    }

    private function morningStatusId(?DaySnapshot $morningPlan, int $dealId): ?int
    {
        return $morningPlan?->leadsById[$dealId]['status_id'] ?? null;
    }

    private function eveningStatusId(DaySnapshot $eveningSnap, int $dealId): ?int
    {
        return $eveningSnap->leadsById[$dealId]['status_id'] ?? null;
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, PulseTaskRow> task_id => row (last write wins; task_ids are unique)
     */
    private function indexByTaskId(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row->taskId] = $row;
        }

        return $map;
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return list<int>
     */
    private function taskIds(array $rows): array
    {
        return array_values(array_map(static fn (PulseTaskRow $r): int => $r->taskId, $rows));
    }

    /**
     * Completed-in-the-evening task ids (fact_task_ids, spec §1.2 metric 4).
     *
     * @param  list<PulseTaskRow>  $rows
     * @return list<int>
     */
    private function doneTaskIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->isCompleted) {
                $ids[] = $row->taskId;
            }
        }

        return $ids;
    }

    /**
     * Unique deal ids referenced by the plan rows (companies, spec §1.2 metric 2).
     *
     * @param  list<PulseTaskRow>  $rows
     * @return list<int>
     */
    private function uniqueDealIds(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            if ($row->dealId !== null) {
                $seen[$row->dealId] = true;
            }
        }

        return array_keys($seen);
    }
}
