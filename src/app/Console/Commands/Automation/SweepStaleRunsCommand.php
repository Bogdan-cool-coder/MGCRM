<?php

declare(strict_types=1);

namespace App\Console\Commands\Automation;

use App\Domain\Automation\Services\AutomationEngine;
use Illuminate\Console\Command;

/**
 * php artisan automation:sweep-stale-runs [--minutes=N]
 *
 * Re-dispatches the execution job for automation_runs stuck in `pending` past
 * the sweep window (a worker that claimed a slot but never resolved it — crash,
 * dropped queue message, or the historical pre-commit dispatch race). Without
 * this a stale `pending` run holds its idempotency slot forever and the action
 * is lost silently.
 *
 * Scheduled every 5 minutes via routes/console.php (withoutOverlapping). Safe to
 * re-run: ExecuteAutomationActionJob is ShouldBeUnique on the run id and bails
 * when the run is no longer `pending`, so a run resolved between two sweeps is a
 * no-op (never a double side-effect).
 */
class SweepStaleRunsCommand extends Command
{
    protected $signature = 'automation:sweep-stale-runs {--minutes= : Age floor in minutes (defaults to config automation.stale_pending_minutes)}';

    protected $description = 'Re-dispatch execution jobs for automation_runs stuck in pending';

    public function handle(AutomationEngine $engine): int
    {
        $minutesOption = $this->option('minutes');
        $minutes = $minutesOption !== null ? (int) $minutesOption : null;

        $redispatched = $engine->sweepStalePending($minutes);

        $this->info("Sweep stale runs: re-dispatched {$redispatched} pending run(s).");

        return self::SUCCESS;
    }
}
