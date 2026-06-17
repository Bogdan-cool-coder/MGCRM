<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * channels — inbound-message receiving points (tg/wa/email/web_form/api).
 *
 * secret_token verifies generic webhooks (constant-time compare). The default_*
 * columns steer routing: an inbound message creates a Deal in default_pipeline_id
 * / default_stage_id (or, when null, the sales pipeline + `code='new'` stage),
 * owned by default_owner_id. Counterparty mirror is NOT created (deprecated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('kind', 16)->index(); // tg|wa|email|web_form|api
            $table->string('secret_token', 64)->index();
            $table->json('config')->default('{}');
            $table->string('default_lead_source', 16)->default('api');

            $table->foreignId('default_owner_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('default_pipeline_id')
                ->nullable()
                ->constrained('pipelines')
                ->restrictOnDelete();
            $table->foreignId('default_stage_id')
                ->nullable()
                ->constrained('pipeline_stages')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
