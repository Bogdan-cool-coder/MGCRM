<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\LinkRedeemResult;
use App\Domain\Notification\Models\TelegramLinkToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TelegramLinkService (S2.9) — issue + redeem one-shot deeplink tokens binding a
 * User to a Telegram account.
 *
 * issueFor() creates a TelegramLinkToken with a TTL and returns the t.me deeplink.
 * redeem() validates the token (not used, not expired, target TG not already taken
 * by another user) inside a DB transaction with a row-lock, then writes
 * users.telegram_user_id and stamps used_at. All guards are fail-closed: any
 * failure returns a LinkRedeemResult without leaking internal IDs.
 */
class TelegramLinkService
{
    /**
     * Issue a fresh deeplink token for the user.
     *
     * @return array{deeplink: string, expires_in_minutes: int}
     */
    public function issueFor(User $user): array
    {
        $ttlMinutes = (int) config('crm.telegram.link_ttl_minutes', 10);

        $token = TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'used_at' => null,
        ]);

        $username = (string) config('crm.telegram.bot_username');

        return [
            'deeplink' => 'https://t.me/'.$username.'?start=link_'.$token->token,
            'expires_in_minutes' => $ttlMinutes,
        ];
    }

    /**
     * Redeem a token against the calling Telegram account.
     *
     * Returns the outcome enum; on success the user is linked and the token is
     * stamped. The bot translates the enum into a RU reply (no IDs/token leaked).
     */
    public function redeem(string $rawToken, string $telegramUserId): LinkRedeemResult
    {
        return DB::transaction(function () use ($rawToken, $telegramUserId): LinkRedeemResult {
            $token = TelegramLinkToken::query()
                ->where('token', $rawToken)
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return LinkRedeemResult::Invalid;
            }

            if ($token->used_at !== null) {
                return LinkRedeemResult::AlreadyUsed;
            }

            if ($token->expires_at->isPast()) {
                return LinkRedeemResult::Expired;
            }

            // Guard: this Telegram account is already bound to a different user.
            $existing = User::query()
                ->where('telegram_user_id', $telegramUserId)
                ->where('id', '!=', $token->user_id)
                ->exists();

            if ($existing) {
                return LinkRedeemResult::LinkedToOther;
            }

            $user = User::query()->lockForUpdate()->find($token->user_id);

            if ($user === null) {
                return LinkRedeemResult::Invalid;
            }

            $user->telegram_user_id = $telegramUserId;
            $user->save();

            $token->used_at = now();
            $token->save();

            return LinkRedeemResult::Linked;
        });
    }

    /**
     * Unlink the user from any Telegram account (clears telegram_user_id).
     */
    public function unlink(User $user): void
    {
        $user->telegram_user_id = null;
        $user->save();
    }
}
