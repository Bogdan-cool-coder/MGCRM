<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * automation_runs — audit history of every automation execution + the
 * idempotency guard.
 *
 * Idempotency contract (mirrored from contracts' automation_executor):
 * a `pending` row is inserted BEFORE the side-effect (claim the slot). The
 * partial-unique index ux_automation_runs_idem on
 * (automation_id, target_type, target_id, trigger_event_ts)
 * WHERE trigger_event_ts IS NOT NULL makes a duplicate claim fail — so a repeated
 * cron scan or a second worker never runs the action twice. A `failed` run
 * releases its slot (the engine nulls trigger_event_ts) so it can be re-claimed;
 * success/skipped/queued keep the slot.
 *
 * Manual runs (retry/dry-run) carry trigger_event_ts = NULL and are excluded from
 * the partial index — they never conflict.
 *
 * The partial predicate WHERE ... IS NOT NULL is supported by both PostgreSQL and
 * SQLite (see the inbound_messages precedent), so no driver fallback is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('automation_id')
                ->constrained('pipeline_automations')
                ->cascadeOnDelete();

            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');

            $table->string('status', 16); // RunStatus enum value
            $table->timestamp('trigger_event_ts')->nullable();

            $table->json('payload')->nullable(); // snapshot of what triggered
            $table->json('result')->nullable();  // what the handler did
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // Deal-card "automation history" lookup.
            $table->index(['target_type', 'target_id'], 'ix_automation_runs_target');
            // Journal by automation + analytics aggregates (read by analytics-specialist).
            $table->index(['automation_id', 'created_at'], 'ix_automation_runs_automation');
        });

        // Partial-unique idempotency guard. NULL trigger_event_ts rows (manual
        // runs / retries) are excluded and never conflict. Raw DDL — not
        // expressible through Blueprint helpers; mirrors the inbound_messages
        // precedent (works on PostgreSQL and SQLite alike).
        DB::statement(
            'CREATE UNIQUE INDEX ux_automation_runs_idem
             ON automation_runs (automation_id, target_type, target_id, trigger_event_ts)
             WHERE trigger_event_ts IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_automation_runs_idem');
        Schema::dropIfExists('automation_runs');
    }
};
