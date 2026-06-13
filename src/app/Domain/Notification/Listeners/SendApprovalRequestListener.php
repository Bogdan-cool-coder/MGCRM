<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Notification\Services\ApprovalNotificationService;

/**
 * SendApprovalRequestListener (S2.9) — on DocumentSubmittedForApproval, post the
 * approval card + inline keyboard to the approval group chat.
 *
 * Synchronous listener: it only dispatches a queued Job (no network here), so
 * the web request that submitted the document is not blocked by Telegram I/O.
 * Registered in AppServiceProvider::boot via Event::listen.
 */
class SendApprovalRequestListener
{
    public function __construct(
        private readonly ApprovalNotificationService $notificationService,
    ) {}

    public function handle(DocumentSubmittedForApproval $event): void
    {
        $this->notificationService->notifyStage(
            $event->document,
            $event->stage,
            $event->attempt,
        );
    }
}
