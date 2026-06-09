<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `scope_type` to chats — the mini-chat widget needs to filter chats by
 * "this report" vs "general (no report)" without overloading the existing
 * `type` column (which means something different: report_generation vs
 * quick_qa — i.e. the LLM prompt cascade, not the UI scope).
 *
 * Enum values are limited to {'report', 'general'} on purpose. Legacy
 * report_generation chats that never received a report_id are backfilled as
 * 'general' — they're effectively abandoned threads with no context to scope
 * to.
 *
 * Stored as string(16) instead of a PG native enum: adding new values to a
 * native enum requires DDL gymnastics in Postgres (CREATE TYPE / ALTER TYPE),
 * whereas a varchar with model-level validation matches the rest of this
 * project (see ChatMessage::STATUSES, Chat::type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('scope_type', 16)->default('general')->after('type');

            // Composite index supports the mini-chat dropdown query:
            //   WHERE user_id=? AND company_id=? AND scope_type=? [AND report_id=?]
            //   ORDER BY updated_at DESC
            // Postgres truncates auto-generated index names at 63 chars and the
            // default name would exceed that, so we pin it explicitly.
            $table->index(
                ['user_id', 'company_id', 'scope_type', 'report_id', 'updated_at'],
                'chats_scope_lookup_idx'
            );
        });

        // Backfill: chats with a report_id => 'report' scope. Everything else
        // stays at the default 'general'. NB: a chat with type=report_generation
        // but no report_id (= the user opened the AI flow but never produced a
        // report) maps to 'general' on purpose — it has no report to scope to.
        DB::table('chats')
            ->whereNotNull('report_id')
            ->update(['scope_type' => 'report']);
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('chats_scope_lookup_idx');
            $table->dropColumn('scope_type');
        });
    }
};
