<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbox_drafts — a per-author, unsent reply note (СРЕЗ B, contract §4.6).
 *
 * Deliberately NOT an outbound-mail domain: no send/status/transport here. That
 * lands with the outbound-mail sprint (sent/spam/trash). This table only backs
 * the "Черновики" folder as a real CRUD of drafts, not a mock-empty folder.
 *
 * Per-author (user_id) — the ONLY per-user Inbox entity (contract §1): a draft
 * is "my unfinished note", unlike the shared triage flags on inbound_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_drafts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Optional link to the inbound message being replied to. nullOnDelete —
            // deleting the source message must not destroy the draft; it just
            // becomes a "free-standing" note.
            $table->foreignId('related_message_id')->nullable()
                ->constrained('inbound_messages')->nullOnDelete();

            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at'], 'ix_inbox_drafts_user_updated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_drafts');
    }
};
