<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ApprovalService;
use App\Domain\Iam\Models\User;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ApprovalDecisionFlow (S2.9) — the shared "cast a vote" logic for the bot.
 *
 * Wraps Contracts\ApprovalService::decide() (which is idempotent and throws
 * 409/403/422) and translates outcomes into bot UI:
 *   - success  → confirmation in chat (+ markup removed for approve)
 *   - 409      → "Договор уже обработан." alert (already decided/closed)
 *   - 403      → "Вы не назначены согласователем…" alert
 *   - 422      → "Вы уже приняли решение." alert
 *
 * ApprovalService is NOT modified — we only call it (owner: contract-specialist).
 *
 * Repeated taps are safe: after a successful vote a second decide() throws
 * 409/422 → polite alert, no duplicate effect.
 */
class ApprovalDecisionFlow
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    /**
     * Approve a document straight from the inline button (no comment).
     * Answers the callback query, removes the keyboard, and posts a confirmation.
     */
    public function approveFromCallback(Nutgram $bot, Document $document, User $user): void
    {
        try {
            $updated = $this->approvalService->decide($document, $user, ApprovalDecision::Approved, null);
        } catch (HttpException $e) {
            $this->answerError($bot, $e->getStatusCode());

            return;
        } catch (ValidationException) {
            $bot->answerCallbackQuery(text: TelegramMessages::ERROR_ALREADY_DECIDED, show_alert: true);

            return;
        }

        $bot->answerCallbackQuery(text: TelegramMessages::DECIDE_APPROVED);
        $this->removeKeyboard($bot);

        $bot->sendMessage($this->approvedConfirmation($user, $updated->status));
    }

    /**
     * Apply a reject/rework decision collected via conversation (with comment).
     * Returns the RU text to send back to the approver. Used by the conversation,
     * which sends the returned string itself.
     */
    public function decideWithComment(
        Document $document,
        User $user,
        ApprovalDecision $decision,
        string $comment,
    ): string {
        try {
            $this->approvalService->decide($document, $user, $decision, $comment);
        } catch (HttpException $e) {
            return $this->errorText($e->getStatusCode());
        } catch (ValidationException) {
            return TelegramMessages::ERROR_ALREADY_DECIDED;
        }

        return TelegramMessages::DECIDE_REASON_SAVED;
    }

    private function approvedConfirmation(User $user, ContractStatus $status): string
    {
        $name = htmlspecialchars((string) $user->full_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $base = "✅ {$name} согласовал.";

        return match ($status) {
            ContractStatus::Approved => $base."\n🎉 Договор полностью согласован.",
            ContractStatus::InReview => $base."\nПередаётся на следующий этап.",
            default => $base,
        };
    }

    private function answerError(Nutgram $bot, int $status): void
    {
        $bot->answerCallbackQuery(text: $this->errorText($status), show_alert: true);

        // On a "closed" verdict (409), strip the now-stale keyboard.
        if ($status === 409) {
            $this->removeKeyboard($bot);
        }
    }

    private function errorText(int $status): string
    {
        return match ($status) {
            403 => TelegramMessages::ERROR_NOT_ASSIGNED,
            409 => TelegramMessages::ERROR_ALREADY_PROCESSED,
            default => TelegramMessages::ERROR_ALREADY_DECIDED,
        };
    }

    private function removeKeyboard(Nutgram $bot): void
    {
        $bot->editMessageReplyMarkup(reply_markup: null);
    }
}
