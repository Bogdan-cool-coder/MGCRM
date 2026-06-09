<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make chat_messages.content nullable.
 *
 * Required by the async chat flow (M4): when a user submits a question the
 * controller creates a placeholder assistant ChatMessage with status='pending'
 * before the AI job runs. Until the job completes the text content is
 * literally not yet known — null is the honest representation.
 *
 * Pre-M4 the controller wrote the user message AND ran sendMessage()
 * synchronously, so by the time the assistant row was created the AI had
 * already produced text. With the async dispatch model, the assistant row is
 * created BEFORE the job runs (so the frontend can show "AI is thinking..."
 * immediately), which means we need to allow nulls.
 *
 * Empty string '' would also work, but null is closer to the actual semantic
 * ("no content yet") and matches the public API contract documented in
 * chats_frontend.md (asyc-flow section): `"content": null` for in-flight
 * assistant messages.
 *
 * Postgres: TEXT column, NULL allowed via ALTER COLUMN ... DROP NOT NULL.
 * Sqlite: doctrine/dbal applies the column rebuild required to relax NOT NULL.
 * The `doctrine/dbal` package is already required by the project.
 *
 * Existing rows are unaffected — they keep their string content; new rows
 * may now write null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill any nulls before re-applying NOT NULL — otherwise the
        // schema change will fail with a constraint violation on legacy rows.
        // Use empty string as the safe default (matches the legacy sync flow
        // which always wrote something).
        \DB::table('chat_messages')->whereNull('content')->update(['content' => '']);

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });
    }
};
