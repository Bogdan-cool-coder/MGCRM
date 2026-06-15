<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pipeline_automations — one automation rule: a trigger that fires an action on
 * a target inside a pipeline.
 *
 * stage_id NULL = the rule applies on every stage of the pipeline; a concrete
 * stage_id scopes it to that stage. The (pipeline_id, stage_id, trigger_kind,
 * is_active) index serves the inline-trigger resolve query.
 *
 * MVP scope: trigger_config / action_config are validated typed by kind at the
 * HTTP layer (P4); the columns store the discriminated JSON. is_sla /
 * escalation_chain are a later phase and intentionally absent here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_automations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('pipeline_id')
                ->constrained('pipelines')
                ->cascadeOnDelete();

            $table->foreignId('stage_id')
                ->nullable()
                ->constrained('pipeline_stages')
                ->cascadeOnDelete(); // NULL = whole pipeline

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('trigger_kind', 32);
            $table->json('trigger_config')->nullable();

            $table->string('action_kind', 32);
            $table->json('action_config')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // Inline-trigger resolve: active rules for a (pipeline, stage|NULL,
            // trigger) tuple.
            $table->index(
                ['pipeline_id', 'stage_id', 'trigger_kind', 'is_active'],
                'ix_pipeline_automations_resolve'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_automations');
    }
};
