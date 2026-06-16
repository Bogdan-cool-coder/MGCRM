<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityAssigned;
use App\Domain\Activity\Models\Activity;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Services\NotificationService;

/**
 * NotifyActivityAssigneeListener (task #9) — on ActivityAssigned, push an IN-APP
 * actionable notification to the new responsible user. This is the primary
 * source of "needs attention" items in the navigation flyout.
 *
 * Guards:
 *   - no responsible ⇒ nothing to notify
 *   - notes are never "assigned work" ⇒ skipped
 *   - self-assignment (creator == responsible) ⇒ skipped (no point pinging the
 *     user about a task they just gave themselves)
 *
 * Synchronous listener: it only writes a DB row (no network I/O), so the web
 * request that created/updated the activity is not blocked. Registered in
 * AppServiceProvider::boot via Event::listen.
 */
class NotifyActivityAssigneeListener
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(ActivityAssigned $event): void
    {
        $activity = $event->activity;

        $responsibleId = $activity->responsible_id;

        if ($responsibleId === null) {
            return;
        }

        // Notes are not actionable work items.
        if ($activity->kind === ActivityType::Note) {
            return;
        }

        // Don't notify a user about a task they assigned to themselves.
        if ((int) $responsibleId === (int) $activity->created_by_id) {
            return;
        }

        $this->notifications->createForUser(
            userId: (int) $responsibleId,
            category: NotificationCategory::Task,
            title: $this->title($activity->kind),
            body: $activity->title,
            isActionable: true,
            actionLabel: $this->actionLabel($activity->kind),
            deepLink: $this->deepLink($activity),
            data: [
                'activity_id' => (int) $activity->id,
                'kind' => $activity->kind?->value,
                'target_type' => $activity->target_type,
                'target_id' => $activity->target_id,
                'due_at' => $activity->due_at?->toIso8601String(),
                'assigned_by_id' => $activity->created_by_id,
            ],
        );
    }

    private function title(?ActivityType $kind): string
    {
        return match ($kind) {
            ActivityType::Call => 'Назначен звонок',
            ActivityType::Meeting => 'Назначена встреча',
            default => 'Назначена задача',
        };
    }

    private function actionLabel(?ActivityType $kind): string
    {
        return match ($kind) {
            ActivityType::Call, ActivityType::Meeting => 'Открыть встречу',
            default => 'Открыть задачу',
        };
    }

    /**
     * Deep link into the responsible user's task board. Targeted activities
     * (deal/company) link to the target; standalone tasks link to the board.
     */
    private function deepLink(Activity $activity): string
    {
        return match ($activity->target_type) {
            'deal' => '/deals/'.$activity->target_id,
            'company' => '/companies/'.$activity->target_id,
            default => '/tasks',
        };
    }
}
