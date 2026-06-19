<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * HistoryService — carryover/days-in-stage derivation from a manager's past PLAN
 * snapshots (port of the AMO bot's history walk, spec §1.4).
 *
 * The history is the ordered list of prior PLAN snapshots' serialized `data`
 * arrays in the window [beforeDate - daysBack, beforeDate), NEWEST FIRST. Both
 * walkers iterate from newest to oldest and stop on the first break — exactly the
 * Python behaviour. These are pure list walks once the history is loaded; the
 * only DB touch is loadPlanHistory.
 */
class HistoryService
{
    /**
     * Prior PLAN snapshots for a manager in [beforeDate - daysBack, beforeDate),
     * newest first. Returns each snapshot's serialized `data` array (the shape
     * DaySnapshot::toArray() produced), so the walkers read tasks[] / leads_by_id
     * without re-hydrating models.
     *
     * @return list<array<string, mixed>>
     */
    public function loadPlanHistory(User $manager, CarbonImmutable $beforeDate, int $daysBack = 60): array
    {
        $from = $beforeDate->subDays($daysBack)->toDateString();
        $before = $beforeDate->toDateString();

        /** @var Collection<int, PulseSnapshot> $snapshots */
        $snapshots = PulseSnapshot::query()
            ->where('manager_id', $manager->id)
            ->where('kind', SnapKind::Plan->value)
            ->where('on_date', '>=', $from)
            ->where('on_date', '<', $before)
            ->orderByDesc('on_date')
            ->get(['id', 'on_date', 'data']);

        return $snapshots
            ->map(static function (PulseSnapshot $s): array {
                /** @var array<string, mixed> $data */
                $data = $s->data ?? [];

                return $data;
            })
            ->all();
    }

    /**
     * carryover_days(task_id) — how many consecutive PRIOR days the task was in the
     * PLAN (spec §1.4). 0 = new today; the streak breaks on the first day the task
     * is absent. History MUST be newest-first.
     *
     * @param  list<array<string, mixed>>  $history
     */
    public function countCarryoverDays(int $taskId, array $history): int
    {
        $days = 0;

        foreach ($history as $snapshot) {
            if ($this->snapshotHasTask($snapshot, $taskId)) {
                $days++;

                continue;
            }

            break;
        }

        return $days;
    }

    /**
     * days_in_stage(lead_id, status) — start n=1 (today always counts), walk from
     * newest to oldest, +1 while the deal's status matches, stop on the first
     * mismatch or absence (spec §1.4). History MUST be newest-first.
     *
     * @param  list<array<string, mixed>>  $history
     */
    public function daysInStage(int $dealId, ?int $currentStatusId, array $history): int
    {
        $days = 1; // Today always counts (spec §1.4).

        foreach ($history as $snapshot) {
            $statusId = $this->leadStatusId($snapshot, $dealId);

            if ($statusId === null) {
                break; // Deal absent that day → stop.
            }

            if ($statusId !== $currentStatusId) {
                break; // Stage changed → stop.
            }

            $days++;
        }

        return $days;
    }

    /**
     * Whether a serialized snapshot's tasks[] contains the given task_id.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasTask(array $snapshot, int $taskId): bool
    {
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $snapshot['tasks'] ?? [];

        foreach ($tasks as $task) {
            if ((int) ($task['task_id'] ?? 0) === $taskId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The deal's status_id as recorded in a snapshot's leads_by_id, or null if the
     * deal was not present in that snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function leadStatusId(array $snapshot, int $dealId): ?int
    {
        /** @var array<string, array<string, mixed>> $leads */
        $leads = $snapshot['leads_by_id'] ?? [];

        $lead = $leads[(string) $dealId] ?? null;
        if ($lead === null) {
            return null;
        }

        $statusId = $lead['status_id'] ?? null;

        return $statusId === null ? null : (int) $statusId;
    }
}
