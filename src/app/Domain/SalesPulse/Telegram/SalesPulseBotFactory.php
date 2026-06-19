<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * SalesPulseBotFactory — builds the SECOND Nutgram instance, the one bound to the
 * SalesPulse bot token (config('salespulse.bot.token')), kept entirely separate
 * from the contract bot's nutgram/laravel singleton.
 *
 * INVARIANT (spec §8 / plan §3): SalesPulse runs on its OWN token, so its
 * getUpdates stream is independent of the contract bot — the two never 409 each
 * other. But the per-token polling invariant still holds: only ONE process
 * (the `salespulse-bot` compose service, replicas:1) may poll this token.
 *
 * An empty token must NOT crash the `salespulse:run` command / container: we fall
 * back to a FakeNutgram (idle placeholder) exactly like config/nutgram.php does
 * for the contract bot. The same instance is registered as a singleton so the
 * running command, the command handlers and SalesPulseNotifier all share it.
 */
class SalesPulseBotFactory
{
    /**
     * Build the SalesPulse Nutgram. Empty token → FakeNutgram fallback (idle).
     */
    public function make(): Nutgram
    {
        $token = config('salespulse.bot.token');

        if ($token === null || $token === '') {
            return FakeNutgram::instance();
        }

        return new Nutgram((string) $token, new Configuration);
    }
}
