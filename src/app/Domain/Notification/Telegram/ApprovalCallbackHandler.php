<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram;

use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\ApprovalCallbackAction;
use App\Domain\Notification\Telegram\Conversations\RejectReasonConversation;
use SergiX44\Nutgram\Nutgram;

/**
 * ApprovalCallbackHandler (S2.9) — handles inline-button votes
 * `apv:{action}:{documentId}`.
 *
 * Flow:
 *   1. Resolve the calling user by telegram_user_id; unlinked → alert, stop
 *      (no document details leaked — §И).
 *   2. Resolve the document; missing → alert.
 *   3. approve → ApprovalDecisionFlow (immediate, no comment).
 *      reject/rework → start RejectReasonConversation to collect the comment.
 *
 * The server re-checks stage membership inside ApprovalService::decide on every
 * tap, so a stale/forged callback cannot bypass authorization.
 */
class ApprovalCallbackHandler
{
    public function __construct(
        private readonly ApprovalDecisionFlow $decisionFlow,
    ) {}

    public function __invoke(Nutgram $bot, string $action, string $documentId): void
    {
        $callbackAction = ApprovalCallbackAction::tryFrom($action);

        if ($callbackAction === null) {
            $bot->answerCallbackQuery();

            return;
        }

        $user = User::query()->where('telegram_user_id', (string) $bot->userId())->first();

        if ($user === null) {
            $bot->answerCallbackQuery(text: TelegramMessages::ERROR_NOT_LINKED, show_alert: true);

            return;
        }

        $document = Document::find((int) $documentId);

        if ($document === null) {
            $bot->answerCallbackQuery(text: TelegramMessages::ERROR_DOC_NOT_FOUND, show_alert: true);

            return;
        }

        if ($callbackAction === ApprovalCallbackAction::Approve) {
            $this->decisionFlow->approveFromCallback($bot, $document, $user);

            return;
        }

        // reject / rework — collect the mandatory comment via conversation.
        RejectReasonConversation::begin(
            bot: $bot,
            data: [
                'documentId' => (int) $document->id,
                'decisionValue' => $callbackAction->toDecision()->value,
            ],
        );
    }
}
