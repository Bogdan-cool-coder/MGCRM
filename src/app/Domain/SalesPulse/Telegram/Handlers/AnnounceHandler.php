<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Services\AnnouncerService;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;

/**
 * AnnounceHandler — /announce_now (admin, spec §4). The MANUAL trigger for the
 * announcer (FTM-meeting / won-deal detection within a 15-minute freshness
 * window).
 *
 * It runs AnnouncerService::run synchronously for the caller's team (the
 * announcements go to the team chat through SalesPulseNotifier; the dedup ledger
 * guarantees no double-post against the scheduled every-5-minute ticks) and
 * acknowledges with the count posted. Admin-gated.
 *
 * AnnouncerService is resolved LAZILY inside __invoke (not constructor-injected):
 * it depends on SalesPulseNotifier → the salespulse.bot singleton, and this
 * handler is itself built DURING that singleton's registration, so eager injection
 * would recurse. By the time a /announce_now message arrives the singleton is
 * fully bound, so the lazy resolve is safe.
 */
class AnnounceHandler
{
    use AdminGate;

    public function __construct(
        private readonly CommandContextResolver $resolver,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        $posted = app(AnnouncerService::class)->run($ctx->team);

        $bot->sendMessage(
            $posted > 0
                ? SalesPulseMessages::announceDone($posted)
                : SalesPulseMessages::ANNOUNCE_NONE,
        );
    }
}
