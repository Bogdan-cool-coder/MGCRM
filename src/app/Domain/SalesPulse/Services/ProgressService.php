<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\ProgressLine;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * ProgressService — the /progress live recompute (spec §6.1). Per manager, it
 * decides the line variant and (for the live one) recomputes four counters from
 * the morning PLAN task_ids against the CURRENT activity state (not the morning
 * snapshot — that is the whole point of "live").
 *
 * Variant order (spec §6.1): vacation → skip → no-plan → zero(total 0) → live.
 *
 * Live counters (spec §6.1) over the morning plan's task_ids:
 *   - done       = task currently completed.
 *   - postponed  = task vanished (no longer a row for the manager) OR its due_at
 *                  is now AFTER the end of the day window (rescheduled forward).
 *   - in_progress= total − done − postponed.
 *   - notes_count= incomplete deals that received a note today.
 *
 * The DB touches: skip lookup, the live Activity rows for the plan task_ids, and
 * (via NotesService) today's notes. Stage data is not needed for /progress.
 */
class ProgressService
{
    public function __construct(
        private readonly DayWindowResolver $window,
        private readonly NotesService $notes,
        private readonly SnapshotRepository $snapshots,
    ) {}

    /**
     * Build the progress line for one manager on a date.
     *
     * @param  string  $nameLink  Pre-built deep link (Slice 3/4 wires the real URL;
     *                            today a placeholder per the milestone note).
     * @param  string|null  $teamChatId  Team chat for the team-skip check.
     */
    public function lineFor(
        User $manager,
        CarbonImmutable $date,
        string $nameLink,
        ?string $teamChatId = null,
        ?string $vacationUntil = null,
    ): ProgressLine {
        $name = (string) $manager->full_name;

        // 1. Vacation.
        if ($vacationUntil !== null && $vacationUntil !== '') {
            return ProgressLine::vacation($name, $nameLink, $vacationUntil);
        }

        // 2. Skip (team or personal).
        if ($this->isSkipped($manager, $date, $teamChatId)) {
            return ProgressLine::skip($name, $nameLink);
        }

        // 3. No morning plan.
        $plan = $this->snapshots->load((int) $manager->id, $date->toDateString(), SnapKind::Plan);
        if ($plan === null) {
            return ProgressLine::noPlan($name, $nameLink);
        }

        $taskIds = $plan->planTaskIds();
        $total = count($taskIds);

        // 4. Plan fixed but empty.
        if ($total === 0) {
            return ProgressLine::zero($name, $nameLink);
        }

        // 5. Live recompute.
        return $this->liveLine($manager, $date, $name, $nameLink, $plan, $taskIds);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function liveLine(
        User $manager,
        CarbonImmutable $date,
        string $name,
        string $nameLink,
        DaySnapshot $plan,
        array $taskIds,
    ): ProgressLine {
        [, $to] = $this->window->dayWindow($date);

        /** @var Collection<int, Activity> $live */
        $live = Activity::query()
            ->whereIn('id', $taskIds)
            ->where('responsible_id', $manager->id)
            ->get(['id', 'status', 'due_at', 'target_id']);

        $liveById = $live->keyBy('id');

        $total = count($taskIds);
        $done = 0;
        $postponed = 0;
        $openDealIds = [];

        foreach ($taskIds as $taskId) {
            /** @var Activity|null $row */
            $row = $liveById->get($taskId);

            // Vanished → postponed (spec §6.1).
            if ($row === null) {
                $postponed++;

                continue;
            }

            if ($row->status === ActivityStatus::Done) {
                $done++;

                continue;
            }

            // Still open: rescheduled forward (due_at > end of day) → postponed.
            if ($row->due_at !== null && $row->due_at->greaterThan($to)) {
                $postponed++;

                continue;
            }

            // Open and in today's window → it is "in progress"; track its deal for
            // the notes-touch count.
            if ($row->target_id !== null) {
                $openDealIds[(int) $row->target_id] = true;
            }
        }

        $inProgress = $total - $done - $postponed;

        // notes_count = incomplete deals that received a note today.
        $notesToday = $this->notes->dealIdsWithNoteToday($manager, $date);
        $notesCount = 0;
        foreach (array_keys($openDealIds) as $dealId) {
            if (isset($notesToday[$dealId])) {
                $notesCount++;
            }
        }

        return ProgressLine::live(
            name: $name,
            nameLink: $nameLink,
            done: $done,
            total: $total,
            postponed: $postponed,
            notesCount: $notesCount,
            inProgress: $inProgress,
        );
    }

    private function isSkipped(User $manager, CarbonImmutable $date, ?string $teamChatId): bool
    {
        $onDate = $date->toDateString();

        $query = PulseSkipDay::query()->whereDate('on_date', $onDate);

        $query->where(function ($q) use ($manager, $teamChatId): void {
            $q->where('manager_id', $manager->id);

            if ($teamChatId !== null && $teamChatId !== '') {
                // A team-wide skip carries no manager_id.
                $q->orWhere(function ($w) use ($teamChatId): void {
                    $w->whereNull('manager_id')->where('team_chat_id', $teamChatId);
                });
            }
        });

        return $query->exists();
    }
}
