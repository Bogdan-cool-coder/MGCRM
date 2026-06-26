<?php

declare(strict_types=1);

namespace App\Console\Commands\SalesPulse;

use App\Domain\SalesPulse\Services\PollLock;
use App\Domain\SalesPulse\Telegram\HeartbeatPolling;
use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use Illuminate\Console\Command;
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
 * The single-process invariant is ALSO code-enforced via a SELF-HEALING poll lock
 * (PollLock): a TTL'd Cache lock plus a heartbeat the live poller refreshes each
 * loop iteration.
 *
 * PROD INCIDENT (fixed): the old lock had NO TTL and was released only in finally{}.
 * A container killed mid-poll never ran finally, leaving the lock orphaned forever;
 * every new container then saw the held lock, exited 0, and `restart: unless-stopped`
 * re-ran it ~every 2s in a tight loop. Two changes break that loop:
 *
 *   - the lock now SELF-HEALS — a held-but-stale lock (dead holder, heartbeat too
 *     old, or TTL lapsed) is auto-reclaimed on startup with no manual --steal;
 *   - a REAL conflict (a live poller with a fresh heartbeat) now exits NON-ZERO, so
 *     Docker's restart backoff applies instead of a 0-exit tight loop.
 *
 * `--steal` remains for manual ops (force-release even a fresh lock).
 */
class RunBotCommand extends Command
{
    /** Exit code used when a live poller already owns the lock (real conflict). */
    private const EXIT_CONFLICT = 2;

    protected $signature = 'salespulse:run {--steal : Force-release the poll lock before acquiring, even if it is live (manual op after an unclean crash)}';

    protected $description = 'Run the SalesPulse Telegram bot (long-polling, single self-healing process per token)';

    public function handle(PollLock $lock): int
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

        if (! $lock->acquire(forceSteal: (bool) $this->option('steal'))) {
            // A LIVE poller already holds the lock. Exit NON-ZERO so Docker's
            // restart backoff kicks in (never a 0-exit tight loop). Use --steal
            // only if you are sure the other process is gone.
            $this->error('Another LIVE SalesPulse poller already holds the lock — refusing to start a second getUpdates (would 409). Use --steal only after confirming the other process is dead.');

            return self::EXIT_CONFLICT;
        }

        try {
            $this->info('SalesPulse bot polling started (single self-healing process — do NOT scale).');

            // Custom running mode: refresh the poll-lock heartbeat on EVERY
            // getUpdates iteration (incl. idle polls) so a live poller never lets
            // its own lock go stale, while a dead poller's heartbeat lapses and the
            // lock is auto-stolen on the next startup.
            $bot->setRunningMode(new HeartbeatPolling($lock));

            $bot->run();
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
