<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram\Conversations;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Telegram\ApprovalDecisionFlow;
use App\Domain\Notification\Telegram\TelegramMessages;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * RejectReasonConversation (S2.9) — collects the mandatory comment for a
 * reject/rework decision, then applies it via ApprovalDecisionFlow.
 *
 * Nutgram serialises the conversation state (documentId + decision) into the
 * cache, keyed by (chatId, userId), between steps — so we don't need to persist
 * a "reject_prompt_message_id" column (the legacy aiogram reply-to mapping).
 *
 * An empty reply re-prompts (the comment is required by ApprovalService).
 */
class RejectReasonConversation extends Conversation
{
    public int $documentId = 0;

    /** ApprovalDecision string value (Rejected|NeedsRework). */
    public string $decisionValue = '';

    public function start(Nutgram $bot, int $documentId, string $decisionValue): void
    {
        // Initial state is passed via Conversation::begin(data: [...]) and spread
        // into this step; persist it on public props so it survives to collectReason.
        $this->documentId = $documentId;
        $this->decisionValue = $decisionValue;

        $bot->answerCallbackQuery();
        $bot->editMessageReplyMarkup(reply_markup: null);

        $prompt = $this->decisionValue === ApprovalDecision::NeedsRework->value
            ? TelegramMessages::DECIDE_REWORK_PROMPT
            : TelegramMessages::DECIDE_REJECT_PROMPT;

        $bot->sendMessage($prompt);

        $this->next('collectReason');
    }

    public function collectReason(Nutgram $bot): void
    {
        $reason = trim((string) ($bot->message()?->text ?? ''));

        if ($reason === '') {
            $bot->sendMessage(TelegramMessages::DECIDE_REASON_REQUIRED);
            $this->next('collectReason');

            return;
        }

        $user = User::query()->where('telegram_user_id', (string) $bot->userId())->first();
        $document = Document::find($this->documentId);

        if ($user === null || $document === null) {
            $bot->sendMessage(
                $user === null
                    ? TelegramMessages::ERROR_NOT_LINKED
                    : TelegramMessages::ERROR_DOC_NOT_FOUND
            );
            $this->end();

            return;
        }

        $flow = app(ApprovalDecisionFlow::class);

        $reply = $flow->decideWithComment(
            $document,
            $user,
            ApprovalDecision::from($this->decisionValue),
            $reason,
        );

        $bot->sendMessage($reply);
        $this->end();
    }
}
