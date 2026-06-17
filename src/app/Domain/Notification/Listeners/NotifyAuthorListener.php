<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Notification\Jobs\SendTelegramDmJob;
use App\Domain\Notification\Telegram\AuthorVerdictText;

/**
 * NotifyAuthorListener (S2.9) — on ApprovalDecisionMade, DM the document author
 * the verdict, but ONLY on a final status (Approved/Rejected/NeedsRework). The
 * intermediate "approved, quorum not yet reached" status (InReview) is ignored
 * so the author is not pinged on every partial vote.
 *
 * Dispatches a queued Job; the DM is silent if the author has no linked Telegram.
 * Registered in AppServiceProvider::boot via Event::listen.
 */
class NotifyAuthorListener
{
    /** @var list<ContractStatus> */
    private const FINAL_STATUSES = [
        ContractStatus::Approved,
        ContractStatus::Rejected,
        ContractStatus::NeedsRework,
    ];

    public function handle(ApprovalDecisionMade $event): void
    {
        if (! in_array($event->newDocumentStatus, self::FINAL_STATUSES, strict: true)) {
            return;
        }

        $authorId = $event->document->author_user_id;

        if ($authorId === null) {
            return;
        }

        $text = AuthorVerdictText::build(
            $event->document,
            $event->approval,
            $event->newDocumentStatus,
        );

        SendTelegramDmJob::dispatch(
            userId: (int) $authorId,
            text: $text,
        );
    }
}
