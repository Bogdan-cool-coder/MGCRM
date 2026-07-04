<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * inbound_messages — triage flags for the СРЕЗ B Mail folders (star / important /
 * snooze). See docs/contracts/inbox-mail-slice-b-contract.md §3.
 *
 * These are SHARED (whole-mailbox) flags, mirroring `read_at` — not per-user
 * (contract §1). Drafts (per-author) live in a separate table/migration.
 *
 *   starred_at    — NULL = not starred; timestamp = moment it was starred.
 *                   Timestamp (not bool) for symmetry with read_at and to allow
 *                   sorting/showing "when starred" without a second column.
 *   important     — bool, no ordering needed; a plain manual flag (like star).
 *   snoozed_until — NULL = not snoozed; timestamp = "show again after".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->timestamp('starred_at')->nullable()->after('read_at');
            $table->boolean('important')->default(false)->after('starred_at');
            $table->timestamp('snoozed_until')->nullable()->after('important');

            $table->index('important', 'ix_inbound_messages_important');
        });

        // Partial indexes (hot WHERE for folder filters) — PG-only raw DDL, guarded
        // so the sqlite test suite is unaffected (§5 backend-standard).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX ix_inbound_messages_starred
                ON inbound_messages (starred_at) WHERE starred_at IS NOT NULL');
            DB::statement('CREATE INDEX ix_inbound_messages_snoozed
                ON inbound_messages (snoozed_until) WHERE snoozed_until IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ix_inbound_messages_snoozed');
            DB::statement('DROP INDEX IF EXISTS ix_inbound_messages_starred');
        }

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex('ix_inbound_messages_important');
            $table->dropColumn(['starred_at', 'important', 'snoozed_until']);
        });
    }
};
