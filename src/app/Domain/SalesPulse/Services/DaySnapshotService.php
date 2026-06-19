<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * DaySnapshotService — in-process port of the AMO bot's `collect_day` (spec §1.1).
 *
 * Path B: reads OUR database directly via Eloquent (no HTTP to AmoCRM). The
 * pipeline of filters mirrors the bot 1-for-1:
 *
 *   1. Raw activities of the manager in the day window (Asia/Dubai), task-like
 *      kinds only (real work, spec §1.5), bound to a deal (target_type=deal +
 *      target_id), where (open AND due_at in window) OR (done AND completed_at in
 *      window).
 *   2. Load the deals, keep only those whose pipeline_id is in the team's funnels.
 *   3. Map each surviving activity → PulseTaskRow (dealStageName = the deal's
 *      CURRENT stage name).
 *   4. plan = every in_work_today row; fact = the subset closed today
 *      (isClosedToday over the day window — spec §1.1 step 7).
 *   5. leads_by_id = { deal_id => {name, status_id, responsible_user_id,
 *      updated_by} } — WITHOUT status_name (spec §2).
 *   6. History enrichment (carryover_days / days_in_stage) via HistoryService.
 *
 * The result DTO is round-trippable into pulse_snapshots.data.
 */
class DaySnapshotService
{
    public function __construct(
        private readonly DayWindowResolver $window,
        private readonly HistoryService $history,
    ) {}

    /**
     * Collect one manager's day across the given sales pipelines (spec §1.1).
     *
     * @param  list<int>  $pipelineIds  The team's funnels; deals outside them are dropped.
     */
    public function collectDay(User $manager, CarbonImmutable $date, array $pipelineIds): DaySnapshot
    {
        [$from, $to] = $this->window->dayWindow($date);

        $activities = $this->relevantActivities($manager, $from, $to);

        // Step 2: load the target deals (current state) and keep only in-funnel ones.
        $dealIds = $activities
            ->map(static fn (Activity $a): int => (int) $a->target_id)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Deal> $deals */
        $deals = Deal::query()
            ->with('stage:id,name,pipeline_id')
            ->whereIn('id', $dealIds)
            ->whereIn('pipeline_id', $pipelineIds)
            ->get(['id', 'title', 'stage_id', 'pipeline_id', 'owner_user_id', 'updated_at']);

        $dealsById = $deals->keyBy('id');

        // Steps 3 + 4: map surviving activities to rows; bucket plan / fact.
        $plan = [];
        $leadsById = [];

        foreach ($activities as $activity) {
            $deal = $dealsById->get((int) $activity->target_id);
            if ($deal === null) {
                continue; // Deal not in one of the team's funnels — drop (step 2).
            }

            $row = $this->mapRow($activity, $deal);
            $plan[] = $row;

            // Step 5: leads_by_id — keyed by deal_id, WITHOUT status_name (spec §2).
            $dealId = (int) $deal->id;
            if (! isset($leadsById[$dealId])) {
                $leadsById[$dealId] = [
                    'name' => $deal->title,
                    'status_id' => $deal->stage_id !== null ? (int) $deal->stage_id : null,
                    'responsible_user_id' => $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null,
                    'updated_by' => $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null,
                ];
            }
        }

        // Step 6: history enrichment (carryover_days / days_in_stage).
        $this->enrichWithHistory($manager, $date, $plan);

        // Step 4 outputs: fact = plan rows closed today.
        $fact = array_values(array_filter(
            $plan,
            static fn (PulseTaskRow $r): bool => $r->isClosedToday($from, $to),
        ));

        return new DaySnapshot(
            managerId: (int) $manager->id,
            managerName: (string) $manager->full_name,
            onDate: $this->window->dayWindow($date)[0]->toDateString(),
            plan: array_values($plan),
            fact: $fact,
            leadsById: $leadsById,
        );
    }

    /**
     * Steps 1+ partial 3 of collect_day: the manager's task-like deal activities
     * that are "in work today" — either open with due_at in the window, or done
     * with completed_at in the window (spec §1.1 steps 1-3).
     *
     * @return Collection<int, Activity>
     */
    private function relevantActivities(User $manager, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Activity::query()
            ->where('responsible_id', $manager->id)
            ->whereIn('kind', PulseTaskRow::realWorkKinds())
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereNotNull('target_id')
            ->where(function ($q) use ($from, $to): void {
                // (not done AND due_at in window) ...
                $q->where(function ($w) use ($from, $to): void {
                    $w->where('status', '!=', ActivityStatus::Done->value)
                        ->whereNotNull('due_at')
                        ->whereBetween('due_at', [$from, $to]);
                })
                    // ... OR (done AND completed_at in window).
                    ->orWhere(function ($w) use ($from, $to): void {
                        $w->where('status', ActivityStatus::Done->value)
                            ->whereNotNull('completed_at')
                            ->whereBetween('completed_at', [$from, $to]);
                    });
            })
            ->get();
    }

    /**
     * Map a surviving activity + its (in-funnel) deal to a PulseTaskRow.
     * dealStageName = the deal's CURRENT stage name (spec §2). updated_at is the
     * row's completion timestamp source for isClosedToday — we feed completed_at
     * when present (a closed task's "closed today" instant), else updated_at.
     */
    private function mapRow(Activity $activity, Deal $deal): PulseTaskRow
    {
        $isCompleted = $activity->status === ActivityStatus::Done;

        // isClosedToday() keys off `updatedAt`; for a completed task the close
        // instant is completed_at, so surface that as the row's updated_at.
        $closeStamp = $isCompleted
            ? ($activity->completed_at ?? $activity->updated_at)
            : $activity->updated_at;

        return new PulseTaskRow(
            taskId: (int) $activity->id,
            text: (string) ($activity->title ?? ''),
            kind: $activity->kind->value,
            typeName: $activity->kind->value,
            isCompleted: $isCompleted,
            dueAt: $activity->due_at?->toIso8601String(),
            updatedAt: $closeStamp?->toIso8601String(),
            responsibleId: $activity->responsible_id !== null ? (int) $activity->responsible_id : null,
            resultText: $activity->result_text,
            dealId: (int) $deal->id,
            dealTitle: $deal->title,
            dealStageId: $deal->stage_id !== null ? (int) $deal->stage_id : null,
            dealStageName: $deal->stage?->name,
            dealOwnerId: $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null,
            dealUpdatedBy: $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null,
            dealPipelineId: $deal->pipeline_id !== null ? (int) $deal->pipeline_id : null,
        );
    }

    /**
     * Fill carryover_days / days_in_stage on every row from the manager's prior
     * PLAN snapshots (spec §1.4). The history is loaded once.
     *
     * @param  list<PulseTaskRow>  $rows
     */
    private function enrichWithHistory(User $manager, CarbonImmutable $date, array $rows): void
    {
        [$dayStart] = $this->window->dayWindow($date);
        $history = $this->history->loadPlanHistory($manager, $dayStart);

        foreach ($rows as $row) {
            $row->carryoverDays = $this->history->countCarryoverDays($row->taskId, $history);

            if ($row->dealId !== null) {
                $row->daysInStage = $this->history->daysInStage($row->dealId, $row->dealStageId, $history);
            }
        }
    }
}
