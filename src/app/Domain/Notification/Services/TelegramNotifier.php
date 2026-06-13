<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Iam\Models\User;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * TelegramNotifier (S2.9) — the single outgoing Bot API entry point.
 *
 * Resolves the shared Nutgram singleton and sends messages WITHOUT polling
 * (sendMessage is a plain Bot API call; getUpdates only runs in the dedicated
 * `bot` container). Every outgoing path in the system (approval card, author DM,
 * automation tg_notify) goes through here so there is one place that talks to
 * Telegram.
 *
 * Downstream consumers (PLAN §Р):
 *   - automation-specialist M7 action `tg_notify`
 *   - integration-specialist M6 outgoing-TG coordination
 */
class TelegramNotifier
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    /**
     * Send HTML text to an arbitrary chat (e.g. the approval group chat).
     *
     * Returns the sent message_id (for later editMessageReplyMarkup), or null if
     * Telegram returned no message.
     */
    public function sendToChat(string $chatId, string $text, ?InlineKeyboardMarkup $keyboard = null): ?int
    {
        $message = $this->bot->sendMessage(
            text: $text,
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
            disable_web_page_preview: true,
            reply_markup: $keyboard,
        );

        return $message?->message_id;
    }

    /**
     * Send an HTML direct message to a linked user. Returns false (silently) when
     * the user has no telegram_user_id — DM is best-effort, never fatal.
     */
    public function sendToUser(User $user, string $text): bool
    {
        $chatId = $user->telegram_user_id;

        if ($chatId === null || $chatId === '') {
            return false;
        }

        $this->bot->sendMessage(
            text: $text,
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
            disable_web_page_preview: true,
        );

        return true;
    }
}
