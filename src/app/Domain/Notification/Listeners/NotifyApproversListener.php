<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Services\NotificationService;

/**
 * NotifyApproversListener (task #9) — on DocumentSubmittedForApproval, push an
 * IN-APP actionable notification to every approver in the current stage. This
 * complements the existing Telegram approval card (SendApprovalRequestListener)
 * with an in-app "needs attention" item in the navigation flyout.
 *
 * The stage payload carries {order, name, user_ids[], min_required}. We fan out
 * one notification per user_id. Writes DB rows only (no network I/O), so it
 * never blocks the submit request. Registered in AppServiceProvider::boot.
 */
class NotifyApproversListener
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(DocumentSubmittedForApproval $event): void
    {
        $document = $event->document;

        /** @var list<int> $userIds */
        $userIds = array_map('intval', $event->stage['user_ids'] ?? []);

        if ($userIds === []) {
            return;
        }

        $stageName = (string) ($event->stage['name'] ?? '');
        $title = $document->number !== null && $document->number !== ''
            ? 'Запрошено согласование № '.$document->number
            : 'Запрошено согласование договора';

        $body = $document->title;

        foreach (array_unique($userIds) as $userId) {
            // Don't ask the submitter to approve their own submission.
            if ($userId === (int) $event->submittedBy) {
                continue;
            }

            $this->notifications->createForUser(
                userId: $userId,
                category: NotificationCategory::Approval,
                title: $title,
                body: $body,
                isActionable: true,
                actionLabel: 'Согласовать',
                deepLink: '/documents/'.$document->id,
                data: [
                    'document_id' => (int) $document->id,
                    'stage_order' => (int) ($event->stage['order'] ?? 1),
                    'stage_name' => $stageName,
                    'attempt' => $event->attempt,
                    'submitted_by_id' => (int) $event->submittedBy,
                ],
            );
        }
    }
}
