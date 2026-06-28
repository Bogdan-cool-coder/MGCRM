<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Jobs\SendTelegramDmJob;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Notification\Telegram\AuthorVerdictText;

/**
 * NotifyAuthorListener (S2.9) — on ApprovalDecisionMade, notify the document
 * author of the verdict, but ONLY on a final status
 * (Approved/Rejected/NeedsRework). The intermediate "approved, quorum not yet
 * reached" status (InReview) is ignored so the author is not pinged on every
 * partial vote.
 *
 * Two channels, on purpose:
 *   - Telegram DM (queued Job; silent if the author has no linked Telegram).
 *   - IN-APP notification (NotificationService::createForUser), so an author
 *     watching only the in-app bell still sees the verdict — approvers already
 *     got both channels (NotifyApproversListener), the author was TG-only.
 *
 * Idempotent: at most one in-app verdict notification per (author, document,
 * verdict). A re-emitted event for the same verdict is a no-op.
 *
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

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(ApprovalDecisionMade $event): void
    {
        if (! in_array($event->newDocumentStatus, self::FINAL_STATUSES, strict: true)) {
            return;
        }

        $authorId = $event->document->author_user_id;

        if ($authorId === null) {
            return;
        }

        $authorId = (int) $authorId;

        $text = AuthorVerdictText::build(
            $event->document,
            $event->approval,
            $event->newDocumentStatus,
        );

        SendTelegramDmJob::dispatch(
            userId: $authorId,
            text: $text,
        );

        $this->createInAppVerdict($event, $authorId);
    }

    /**
     * Mirror the verdict into the author's in-app feed. Idempotent per
     * (author, document, verdict) so a duplicate event never doubles the bell.
     */
    private function createInAppVerdict(ApprovalDecisionMade $event, int $authorId): void
    {
        $document = $event->document;
        $documentId = (int) $document->id;
        $verdict = $event->newDocumentStatus->value;

        $alreadyNotified = Notification::query()
            ->forUser($authorId)
            ->where('category', NotificationCategory::Approval->value)
            ->where('data->document_id', $documentId)
            ->where('data->verdict', $verdict)
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $number = $document->number !== null && $document->number !== ''
            ? (string) $document->number
            : null;

        $title = $this->verdictTitle($event->newDocumentStatus, $number);
        $comment = (string) ($event->approval->comment ?? '');

        $this->notifications->createForUser(
            userId: $authorId,
            category: NotificationCategory::Approval,
            title: $title,
            body: $comment !== '' ? $comment : $document->title,
            isActionable: false,
            deepLink: '/documents/'.$documentId,
            data: [
                'document_id' => $documentId,
                'verdict' => $verdict,
                'approval_id' => (int) $event->approval->id,
            ],
        );
    }

    private function verdictTitle(ContractStatus $status, ?string $number): string
    {
        $suffix = $number !== null ? ' № '.$number : '';

        return match ($status) {
            ContractStatus::Approved => 'Договор согласован'.$suffix,
            ContractStatus::Rejected => 'Договор отклонён'.$suffix,
            ContractStatus::NeedsRework => 'Договор на доработку'.$suffix,
            default => 'Решение по согласованию'.$suffix,
        };
    }
}
