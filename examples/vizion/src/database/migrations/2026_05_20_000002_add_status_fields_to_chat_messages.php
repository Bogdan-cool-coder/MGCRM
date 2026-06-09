<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add async-flow status fields to chat_messages.
 *
 * Powers the streaming/async chat pipeline (M2 of the chat async rollout —
 * see M4 ProcessChatMessageJob + ChatEventEmitter). The status field marks the
 * lifecycle of a single assistant turn:
 *   pending   — message row created, job queued, no work started
 *   running   — job picked up, AI / tool calls in flight
 *   done      — finished successfully (final assistant content written)
 *   error     — job failed (error captured in metadata)
 *   cancelled — user / system cancelled the in-flight job
 *
 * Status is intentionally a plain string + model-level validation (matches the
 * project convention — no DB enums). All pre-existing rows are stamped 'done'
 * by the column default: everything already persisted is a finalised message.
 *
 * job_id stores Laravel queue UUID (for debug / cancel lookups). started_at /
 * finished_at are wall-clock anchors for latency analytics (we already store
 * usage in metadata, but explicit columns make indexable timing queries
 * trivial).
 *
 * The composite (chat_id, status) index supports the hot lookup "does this
 * chat have any active (pending|running) assistant message right now?", which
 * the controller uses to refuse concurrent send-message attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('status', 20)->default('done')->after('metadata');
            $table->string('job_id', 64)->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('job_id');
            $table->timestamp('finished_at')->nullable()->after('started_at');

            $table->index(['chat_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['chat_id', 'status']);
            $table->dropColumn(['status', 'job_id', 'started_at', 'finished_at']);
        });
    }
};
