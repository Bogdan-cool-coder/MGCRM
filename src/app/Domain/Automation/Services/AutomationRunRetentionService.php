<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Models\AutomationRun;
use Illuminate\Support\Carbon;

/**
 * AutomationRunRetentionService (M7) — bounds the growth of the automation_runs
 * journal.
 *
 * Every cron tick and inline trigger appends an AutomationRun (the idempotency
 * + audit row). Left unbounded that table grows forever and the journal /
 * analytics queries (AutomationRunQueryService) degrade. This service prunes
 * rows older than the retention window in one bulk DELETE, driven by the daily
 * `automation:prune-runs` command.
 *
 * Cut-off is by created_at (the insert timestamp; the model tracks no
 * updated_at). The default window comes from config('automation.retention_days')
 * and can be overridden per-invocation by the command's --days flag.
 */
class AutomationRunRetentionService
{
    /**
     * Delete automation_runs older than $days days. Returns the number of rows
     * removed. A non-positive $days is clamped to 1 so a misconfiguration can
     * never wipe the whole table.
     */
    public function prune(int $days): int
    {
        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);

        return AutomationRun::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
