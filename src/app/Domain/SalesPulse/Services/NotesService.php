<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * NotesService — "которые сделки получили заметку сегодня" detector (port of the
 * AMO bot's common-note scan, spec §1.2 metric 3 input). A deal counts when the
 * manager left a `note` activity on it whose created_at falls inside the day
 * window (Asia/Dubai). Used by the `missed` metric: a plan task that is not done
 * but whose deal HAS a note today is NOT missed (it was touched).
 *
 * Pure read service. The day window is resolved by DaySnapshotService and the
 * note kind is the Activity domain's own ActivityType::Note (note carries no
 * deadline and is excluded from "real work", spec §1.5).
 */
class NotesService
{
    public function __construct(
        private readonly DayWindowResolver $window,
    ) {}

    /**
     * Set of deal ids that received a note from this manager today.
     *
     * @return array<int, true> deal_id => true (cheap membership set)
     */
    public function dealIdsWithNoteToday(User $manager, CarbonImmutable $date): array
    {
        [$from, $to] = $this->window->dayWindow($date);

        /** @var Collection<int, Activity> $notes */
        $notes = Activity::query()
            ->where('responsible_id', $manager->id)
            ->where('kind', ActivityType::Note->value)
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereNotNull('target_id')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'target_id']);

        $set = [];
        foreach ($notes as $note) {
            if ($note->target_id !== null) {
                $set[(int) $note->target_id] = true;
            }
        }

        return $set;
    }
}
