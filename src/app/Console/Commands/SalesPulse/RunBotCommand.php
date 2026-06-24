<?php

declare(strict_types=1);

namespace App\Console\Commands\SalesPulse;

use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * php artisan salespulse:run
 *
 * Long-polling entry point for the SalesPulse bot (the SECOND bot, on its OWN
 * token). Runs in the dedicated `salespulse-bot` compose service ONLY.
 *
 * INVARIANT (spec §8 / plan §3): exactly ONE process may poll the SalesPulse
 * token. A parallel getUpdates → Telegram 409 Conflict + dropped updates, so
 * SALESPULSE_RUN_POLLING is set true ONLY in that container. Web/queue resolve the
 * same singleton purely as an outbound client (SalesPulseNotifier) and never poll.
 *
 * The handler set is already bound on the singleton (AppServiceProvider). An empty
 * token resolves to a FakeNutgram (idle) — the command exits cleanly instead of
 * crash-looping the container.
 *
 * The single-process invariant is now ALSO code-enforced: before polling we grab a
 * cluster-wide Cache lock (Redis in prod). If a second process is already polling,
 * the lock cannot be obtained and this process exits instead of starting a parallel
 * getUpdates that would 409 + drop updates. (replicas:1 on the compose service is
 * still the primary guard; this is defence in depth.)
 *
 * The lock is held WITHOUT a TTL for the life of the poll loop and released in the
 * finally block; a graceful restart releases it so the new poller takes over. If the
 * process is killed uncleanly the operator force-recreates the single `salespulse-bot`
 * service, which clears the stale lock (forceRelease via salespulse:run --steal) — see
 * the --steal flag below for an unattended takeover.
 */
class RunBotCommand extends Command
{
    /** Cluster-wide lock key — one poller per SalesPulse token. */
    private const POLL_LOCK = 'salespulse:poll-lock';

    protected $signature = 'salespulse:run {--steal : Force-release a stale poll lock before acquiring (use after an unclean crash)}';

    protected $description = 'Run the SalesPulse Telegram bot (long-polling, single process per token)';

    public function handle(): int
    {
        if (! (bool) config('salespulse.bot.run_polling', false)) {
            $this->warn('SalesPulse polling disabled (SALESPULSE_RUN_POLLING=false). Nothing to do.');

            return self::SUCCESS;
        }

        /** @var Nutgram $bot */
        $bot = $this->laravel->make(SalesPulseBot::BINDING);

        if ($bot instanceof FakeNutgram) {
            $this->warn('SALESPULSE_BOT_TOKEN is empty — running on a FakeNutgram (idle). Set the token to poll.');

            return self::SUCCESS;
        }

        // No TTL: the lock lives for the whole poll loop and is released in finally,
        // so the single poller can hand off cleanly on restart.
        $lock = Cache::lock(self::POLL_LOCK);

        if ($this->option('steal')) {
            // Clear a lock orphaned by an unclean crash before re-acquiring.
            $lock->forceRelease();
        }

        if (! $lock->get()) {
            $this->warn('Another SalesPulse poller already holds the poll lock — refusing to start a second getUpdates (would 409). Use --steal after an unclean crash.');

            return self::SUCCESS;
        }

        try {
            $this->info('SalesPulse bot polling started (single process — do NOT scale).');

            $bot->run();
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
