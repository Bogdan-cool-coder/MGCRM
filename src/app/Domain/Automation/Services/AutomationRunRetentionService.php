<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\RunStatus;
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
 *
 * IMPORTANT (idempotency safety): a row whose trigger_event_ts is set AND whose
 * status still holds the idempotency slot (success / skipped / queued) is the
 * ONLY thing stopping a cron re-scan from re-deriving the same deterministic key
 * and re-firing the action. Deleting it frees the slot and causes a duplicate
 * Telegram/webhook/task/document for an already-handled event. So the prune
 * deliberately spares slot-holding rows and only removes:
 *   - failed runs (the slot was already released — trigger_event_ts nulled), and
 *   - rows with no trigger_event_ts (manual/inline runs that never claimed a slot).
 * Slot-holding rows are kept until the deal itself moves on (a real stage
 * re-entry / date edit moves the deterministic key forward), at which point a
 * superseding row exists and the stale one is harmless audit history. Operators
 * who need to reclaim that space can use a deal-archival / cascade path; never a
 * blind age-based delete.
 */
class AutomationRunRetentionService
{
    /**
     * Delete automation_runs older than $days days that do NOT hold an
     * idempotency slot. Returns the number of rows removed. A non-positive $days
     * is clamped to 1 so a misconfiguration can never wipe the whole table.
     */
    public function prune(int $days): int
    {
        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);

        return AutomationRun::query()
            ->where('created_at', '<', $cutoff)
            // Only prune rows that no longer guard a dedup key: either they never
            // claimed a slot (trigger_event_ts IS NULL) or they failed (slot was
            // released). Slot-holding terminal rows (success/skipped/queued with a
            // trigger_event_ts) are kept to preserve the idempotency guarantee.
            ->where(function ($q): void {
                $q->whereNull('trigger_event_ts')
                    ->orWhere('status', RunStatus::Failed->value);
            })
            ->delete();
    }
}
