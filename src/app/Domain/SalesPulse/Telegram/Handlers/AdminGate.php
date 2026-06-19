<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Telegram\CommandContext;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;

/**
 * AdminGate — shared admin-gating for the admin-only SalesPulse commands (spec §8:
 * /dayresults /weeklyreport /conversions /announce_now /skipday /unskipday
 * /vacation /unvacation).
 *
 *   - Foreign chat (no team) → silently ignored (return false, no reply).
 *   - In a team but not an admin → "⛔ Команда доступна только админу." (false).
 *   - Admin → true.
 */
trait AdminGate
{
    private function passesAdminGate(Nutgram $bot, CommandContext $ctx): bool
    {
        if (! $ctx->hasTeam()) {
            return false; // foreign chat → ignore
        }

        if (! $ctx->isAdmin) {
            $bot->sendMessage(SalesPulseMessages::ADMIN_ONLY);

            return false;
        }

        return true;
    }
}
