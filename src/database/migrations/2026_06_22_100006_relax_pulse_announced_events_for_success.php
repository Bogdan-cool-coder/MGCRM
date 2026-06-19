<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 4 — the announcer detects TWO event sources (spec §4):
 *   - MeetingDone: a completed first-time-meeting Activity → has an activity_id.
 *   - Success:    a DealStageHistory transition into a won stage → has NO
 *                 activity_id (it is not a task), but a deal_stage_history_id.
 *
 * The original table made activity_id a UNIQUE, NOT NULL FK, which cannot hold a
 * Success row. This migration:
 *   - drops the single-column unique on activity_id,
 *   - makes activity_id NULLABLE,
 *   - adds a NULLABLE deal_stage_history_id FK (the Success dedup key),
 *   - rebuilds dedup as TWO partial-style uniques (one per source key) so
 *     MeetingDone dedups on activity_id and Success dedups on
 *     deal_stage_history_id — one announcement per source, surviving cron restarts.
 *
 * SQLite (test DB) lacks partial indexes, but a plain UNIQUE over a nullable
 * column already treats multiple NULLs as distinct on both SQLite and Postgres,
 * so a Success row (activity_id NULL) never collides on the activity_id unique,
 * and a MeetingDone row (deal_stage_history_id NULL) never collides on the
 * deal_stage_history_id unique. A plain UNIQUE per column is therefore correct
 * on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the strict NOT NULL + single unique on activity_id. SQLite needs the
        // index dropped before the column can be altered to nullable.
        Schema::table('pulse_announced_events', function (Blueprint $table): void {
            $table->dropUnique('uq_pulse_announced_events_activity');
        });

        Schema::table('pulse_announced_events', function (Blueprint $table): void {
            // MeetingDone source: now nullable (a Success row carries no activity).
            $table->foreignId('activity_id')->nullable()->change();

            // Success source: the DealStageHistory transition that won the deal.
            $table->foreignId('deal_stage_history_id')
                ->nullable()
                ->after('activity_id')
                ->constrained('deal_stage_history')
                ->cascadeOnDelete();
        });

        // Rebuild dedup. Each source key is unique on its own column; the other
        // column is NULL for that source, and NULLs are distinct in a UNIQUE
        // index (both SQLite and Postgres), so the two never interfere.
        Schema::table('pulse_announced_events', function (Blueprint $table): void {
            $table->unique('activity_id', 'uq_pulse_announced_events_activity');
            $table->unique('deal_stage_history_id', 'uq_pulse_announced_events_dsh');
        });
    }

    public function down(): void
    {
        Schema::table('pulse_announced_events', function (Blueprint $table): void {
            $table->dropUnique('uq_pulse_announced_events_dsh');
            $table->dropUnique('uq_pulse_announced_events_activity');
            $table->dropConstrainedForeignId('deal_stage_history_id');
        });

        // Restore the original strict shape: activity_id NOT NULL + single unique.
        // (Reversible only when no Success rows exist — those have a NULL
        // activity_id; the down path assumes the table was emptied of them, which
        // matches a clean rollback before any Success was recorded.)
        Schema::table('pulse_announced_events', function (Blueprint $table): void {
            $table->foreignId('activity_id')->nullable(false)->change();
            $table->unique('activity_id', 'uq_pulse_announced_events_activity');
        });
    }
};
