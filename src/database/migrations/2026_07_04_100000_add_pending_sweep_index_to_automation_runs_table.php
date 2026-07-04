<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the partial index backing the stale-pending sweep (Э3 side-effect
 * hardening). AutomationEngine::sweepStalePending() scans for runs stuck in
 * `pending` older than N minutes (a worker that claimed a slot but died before
 * ExecuteAutomationActionJob resolved it, or a pre-commit dispatch race). Almost
 * every automation_runs row is a terminal success/skipped/queued/failed — only a
 * transient handful are ever `pending` — so a partial index WHERE status='pending'
 * keeps the sweep an index-only lookup instead of a full-table scan as the 90-day
 * journal grows.
 *
 * Raw DDL: a partial predicate is not expressible through Blueprint helpers.
 * WHERE ... = 'pending' is supported by both PostgreSQL and SQLite (mirrors the
 * ux_automation_runs_idem partial-unique precedent), so no driver fallback is
 * needed. started_at is the sweep's age column (stamped at claim time).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX ix_automation_runs_pending
             ON automation_runs (started_at)
             WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        // SQLite honours DROP INDEX; on pgsql this is equally valid.
        if (Schema::hasTable('automation_runs')) {
            DB::statement('DROP INDEX IF EXISTS ix_automation_runs_pending');
        }
    }
};
