<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound_messages.read_at — Gmail-style read state for the Inbox triage UI.
 *
 * The Inbox is a SHARED mailbox (admin/director triage), so read state lives on
 * the message itself, not per-user: once anyone opens a message it is read for
 * everyone. NULL = unread; a timestamp = the instant it was first marked read.
 *
 * Indexed because the sidebar unread badge (read_at IS NULL count) and the
 * `unread` list filter both query this column on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->timestamp('read_at')->nullable()->after('routing_status');
            $table->index('read_at', 'ix_inbound_messages_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex('ix_inbound_messages_read_at');
            $table->dropColumn('read_at');
        });
    }
};
