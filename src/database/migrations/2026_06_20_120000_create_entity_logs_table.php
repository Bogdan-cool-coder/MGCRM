<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * entity_logs — polymorphic, append-only action/event log across deal, company
 * and contact. One row per discrete domain event (created, stage_changed,
 * contact_added, meeting_held, task_completed, data_changed, contract_event,
 * finance_event). Detail lives in the `meta` JSON column.
 *
 * Polymorphism is FK-less (subject_type string + subject_id int), mirroring
 * activities (target_type/target_id) and crm_files (owner_entity_*) — extending
 * the subject whitelist needs no migration. actor_id is a nullable FK to users
 * (system / inbound events have no actor). Rows are never mutated, so only
 * created_at is tracked (no updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action', 40);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            // Hot path: the entity-log endpoint filters by (subject_type,
            // subject_id) and orders by created_at desc.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'ix_entity_logs_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_logs');
    }
};
