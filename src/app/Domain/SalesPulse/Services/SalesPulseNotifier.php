<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * SalesPulseNotifier — the single OUTBOUND entry point for the SalesPulse bot
 * (analogue of Notification\Services\TelegramNotifier, but on the SalesPulse
 * token / instance).
 *
 * It resolves the SalesPulse Nutgram singleton (tagged @see SalesPulseBot::BINDING)
 * and sends WITHOUT polling — sendMessage is a plain Bot API call; getUpdates only
 * runs inside the dedicated `salespulse-bot` container. Command handlers reply
 * through here, and Slice 4 (scheduler / announcer) posts through here too.
 */
class SalesPulseNotifier
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    /**
     * Send HTML text to a chat (team chat or DM). Returns the sent message_id, or
     * null when Telegram returned no message.
     */
    public function sendToChat(string $chatId, string $html, ?InlineKeyboardMarkup $keyboard = null): ?int
    {
        $message = $this->bot->sendMessage(
            text: $html,
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
            disable_web_page_preview: true,
            reply_markup: $keyboard,
        );

        return $message?->message_id;
    }

    /**
     * Reference so consumers can spot the binding name in one place.
     */
    public static function botBinding(): string
    {
        return SalesPulseBot::BINDING;
    }
}
