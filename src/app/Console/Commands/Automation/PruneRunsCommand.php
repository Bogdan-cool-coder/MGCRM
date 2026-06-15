<?php

declare(strict_types=1);

namespace App\Console\Commands\Automation;

use App\Domain\Automation\Services\AutomationRunRetentionService;
use Illuminate\Console\Command;

/**
 * php artisan automation:prune-runs [--days=N]
 *
 * Deletes automation_runs older than the retention window so the audit /
 * idempotency journal cannot grow without bound. Defaults to
 * config('automation.retention_days') (90); --days overrides it for ad-hoc
 * pruning.
 *
 * Scheduled daily via routes/console.php (dailyAt 03:00, withoutOverlapping).
 * Safe to re-run: a second pass over an already-pruned window simply removes 0
 * rows.
 */
class PruneRunsCommand extends Command
{
    protected $signature = 'automation:prune-runs {--days= : Retention window in days (defaults to config automation.retention_days)}';

    protected $description = 'Delete automation_runs older than the retention window';

    public function handle(AutomationRunRetentionService $retention): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption !== null
            ? (int) $daysOption
            : (int) config('automation.retention_days', 90);

        $deleted = $retention->prune($days);

        $this->info("Prune runs: removed {$deleted} automation run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
