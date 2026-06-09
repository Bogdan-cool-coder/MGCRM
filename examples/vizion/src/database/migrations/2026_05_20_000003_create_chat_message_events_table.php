<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streaming event log for a single assistant ChatMessage.
 *
 * Each row is one durable, append-only step of a job (M4 ProcessChatMessageJob
 * + ChatEventEmitter). The frontend long-polls / streams events by sequence so
 * the user sees thinking → tool_call → tool_result → final_message unfold.
 *
 * Event types (documented; not enforced via DB enum):
 *   started, thinking, tool_call, tool_result, dry_run_start, dry_run_result,
 *   retry, final_message, error.
 *
 * The (chat_message_id, sequence) unique index is the safety net against
 * double-writes on job retry: if the job restarts mid-run, attempting to write
 * the same sequence number twice fails fast at the DB level instead of
 * silently emitting duplicate events to the frontend.
 *
 * Cascade-on-delete on chat_message_id means deleting the parent message
 * (admin tooling or test teardown) cleans up its event log automatically — no
 * orphan rows in the audit log.
 *
 * Events are immutable; no updated_at. created_at is the only timestamp.
 *
 * payload is jsonb for portability (postgres jsonb in prod, sqlite text-as-json
 * in tests). Default in the model is [] — we never want to read a null payload
 * downstream and have to nil-guard everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('chat_message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('type', 40);
            $table->jsonb('payload');
            $table->timestamp('created_at')->useCurrent();

            // (chat_message_id, sequence) is the primary lookup pattern and
            // also doubles as the dedupe guarantee for job retries.
            $table->unique(['chat_message_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_events');
    }
};
