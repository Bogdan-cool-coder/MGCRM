<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Contracts\Models\Document;
use App\Domain\Notification\Services\ApprovalNotificationService;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * SendTelegramApprovalCardJob (S2.9) — sends the approval card + inline keyboard
 * to the approval group chat and stores the returned message_id on the document
 * (for later editMessageReplyMarkup when a verdict is reached).
 *
 * tries=3 with backoff for transient Telegram failures (429/network). A 4xx
 * TelegramException (bad request) is logged and the job ends without re-throwing.
 *
 * Idempotent on retry: if document.telegram_message_id is already set for the
 * current attempt, the card was already delivered — skip the resend so a retry
 * does not double-post into the chat.
 *
 * PHP 8.5 + Queueable: queue is set via $this->onQueue() in the constructor —
 * NEVER a `public string $queue` property (fatal conflict with the trait).
 */
class SendTelegramApprovalCardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly int $documentId,
        private readonly string $chatId,
        private readonly string $text,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        TelegramNotifier $notifier,
        ApprovalNotificationService $notificationService,
    ): void {
        $document = Document::find($this->documentId);

        if ($document === null) {
            return;
        }

        // Idempotency pre-check: card already delivered for this document → skip.
        if ($document->telegram_message_id !== null) {
            return;
        }

        try {
            $messageId = $notifier->sendToChat(
                $this->chatId,
                $this->text,
                $notificationService->buildKeyboard($this->documentId),
            );
        } catch (TelegramException $e) {
            // 4xx bad-request — log, do not retry (would just fail again).
            Log::warning('SendTelegramApprovalCardJob: Telegram rejected the card', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($messageId !== null) {
            $document->forceFill(['telegram_message_id' => $messageId])->save();
        }
    }
}
