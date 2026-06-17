<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\LinkRedeemResult;
use App\Domain\Notification\Services\TelegramLinkService;
use SergiX44\Nutgram\Nutgram;

/**
 * StartHandler (S2.9) — handles /start, with an optional deeplink payload.
 *
 *   /start link_<token>  → redeem the link token (bind this TG account to a User)
 *   /start               → greet (linked → name + role; unlinked → how to link)
 *
 * Security (§И): never echoes the token, an email or any internal ID. On success
 * the reply shows the full name + role only.
 */
class StartHandler
{
    public function __construct(
        private readonly TelegramLinkService $linkService,
    ) {}

    public function __invoke(Nutgram $bot, ?string $payload = null): void
    {
        $telegramUserId = (string) $bot->userId();

        if ($payload !== null && str_starts_with($payload, 'link_')) {
            $this->handleLink($bot, $telegramUserId, substr($payload, 5));

            return;
        }

        $this->greet($bot, $telegramUserId);
    }

    private function handleLink(Nutgram $bot, string $telegramUserId, string $token): void
    {
        $result = $this->linkService->redeem($token, $telegramUserId);

        $fullName = '';
        if ($result === LinkRedeemResult::Linked) {
            $fullName = (string) (User::query()
                ->where('telegram_user_id', $telegramUserId)
                ->value('full_name') ?? '');
        }

        $bot->sendMessage(TelegramMessages::forRedeem($result, $fullName));
    }

    private function greet(Nutgram $bot, string $telegramUserId): void
    {
        $user = User::query()->where('telegram_user_id', $telegramUserId)->first();

        if ($user === null) {
            $bot->sendMessage(TelegramMessages::START_UNLINKED);

            return;
        }

        $bot->sendMessage(TelegramMessages::startLinked(
            (string) $user->full_name,
            TelegramMessages::roleLabel($user->role),
        ));
    }
}
