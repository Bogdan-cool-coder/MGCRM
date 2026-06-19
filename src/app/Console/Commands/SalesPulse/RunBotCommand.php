<?php

declare(strict_types=1);

namespace App\Console\Commands\SalesPulse;

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
 */
class RunBotCommand extends Command
{
    protected $signature = 'salespulse:run';

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

        $this->info('SalesPulse bot polling started (single process — do NOT scale).');

        $bot->run();

        return self::SUCCESS;
    }
}
