<?php

declare(strict_types=1);

namespace App\Support\System;

use App\Domain\Log\Models\EntityLog;
use App\Support\System\Contracts\CategoryPurger;

/**
 * LogPurger — the `logs` category cleaner for the `entity_logs` table.
 *
 * WHY THIS LIVES IN app/Support (not a domain): Domain/Log has no domain agent —
 * backend-architect owns it (per role split), so its purge logic is authored here
 * alongside the orchestrator. It is still boundary-clean: it only touches the Log
 * domain's own `entity_logs` table (via the Log model), exactly as a domain-owned
 * purger would. The Automation-owned `automation_runs` and Sales-owned
 * `deal_audits`/`deal_stage_history` (also part of the `logs` category per contract
 * §1 row 7) are purged by their OWN domains' purgers, registered against the same
 * `logs` category in the SystemResetService map — this class never touches them.
 *
 * ORDERING HAZARD (contract §4): this wipes the audit trail itself. The reset's own
 * mandatory audit row is written by SystemResetService AFTER all category
 * transactions commit, so it is a fresh insert into the freshly-emptied table — the
 * reset trace always survives. This purger must therefore NOT be the thing that
 * writes the audit row (it only deletes); the orchestrator owns the post-hoc audit.
 */
final class LogPurger implements CategoryPurger
{
    /**
     * Delete every entity_logs row and return the count removed. Truncate-by-delete
     * (not TRUNCATE) so it composes inside the orchestrator's DB::transaction() and
     * behaves identically on pgsql and the sqlite test suite. No FK children hang
     * off entity_logs (FK-less polymorphic subject), so a single delete suffices.
     */
    public function purgeAll(): int
    {
        return EntityLog::query()->delete();
    }
}
