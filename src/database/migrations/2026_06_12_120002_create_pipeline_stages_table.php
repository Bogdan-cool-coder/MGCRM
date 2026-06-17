<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')
                ->constrained('pipelines')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('code', 32);
            $table->integer('sort_order')->default(0);
            $table->string('color', 16)->nullable();
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('hidden_by_default')->default(false);
            // Self-FK added separately below (self-reference pattern).
            $table->unsignedBigInteger('parent_stage_id')->nullable();
            $table->json('stage_features')->default('[]');
            $table->boolean('won_gate')->default(false);
            $table->integer('sla_hours')->nullable();
            $table->json('visible_department_ids')->nullable();
            $table->json('visible_user_ids')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'code'], 'ix_pipeline_stages_pipeline_code');
            $table->index(['pipeline_id', 'sort_order'], 'ix_pipeline_stages_pipeline_sort');
            $table->index('parent_stage_id', 'ix_pipeline_stages_parent');

            $table->foreign('parent_stage_id')
                ->references('id')
                ->on('pipeline_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
