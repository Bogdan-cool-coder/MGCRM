<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * inbound_messages — audit log of incoming messages plus routing result.
 *
 * Cross-domain FK: target_deal_id → deals.id (Sales) — read-link, nullOnDelete.
 *
 * Webhook-delivery dedup is enforced by a PARTIAL unique index on
 * (channel_id, external_id) WHERE external_id IS NOT NULL — a single message can
 * be delivered/retried many times but is routed to a Deal only once. Form
 * submissions without a contact carry external_id = NULL and never conflict.
 *
 * Both PostgreSQL and SQLite support partial unique indexes; the only portability
 * note is that SQLite stores booleans as integers (not relevant here — the
 * predicate uses IS NOT NULL). The index is raw DDL (not expressible through
 * Blueprint helpers), mirroring the crm_contact_company_links precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('channel_id')
                ->constrained('channels')
                ->cascadeOnDelete();
            $table->index('channel_id', 'ix_inbound_messages_channel');

            $table->string('external_id', 128)->nullable();
            $table->string('from_identifier', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->json('raw_payload')->nullable();

            // Sales cross-domain — read-link to the routed deal.
            $table->foreignId('target_deal_id')
                ->nullable()
                ->constrained('deals')
                ->nullOnDelete();
            $table->index('target_deal_id', 'ix_inbound_messages_target_deal');
            $table->boolean('target_deal_created')->default(false);

            $table->string('routing_status', 16)->nullable(); // routed|dedup|failed
            $table->timestamp('received_at')->useCurrent()->index();
        });

        // Partial unique: dedup webhook deliveries at the DB level. NULL
        // external_id rows (contact-less form submissions) are excluded.
        DB::statement(
            'CREATE UNIQUE INDEX ux_inbound_messages_channel_external
             ON inbound_messages (channel_id, external_id)
             WHERE external_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_inbound_messages_channel_external');
        Schema::dropIfExists('inbound_messages');
    }
};
