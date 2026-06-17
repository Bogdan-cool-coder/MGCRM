<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_report_questions', function (Blueprint $table): void {
            $table->id();

            // NULL = global question (applies to all pipelines); else scoped to a
            // pipeline. CASCADE keeps the registry consistent with funnel deletion
            // (S1.5 stage-cascade semantics).
            $table->foreignId('pipeline_id')
                ->nullable()
                ->constrained('pipelines')
                ->cascadeOnDelete();

            $table->text('text');
            $table->string('kind', 16)->default('text'); // 'text' | 'select'
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['pipeline_id', 'is_active', 'sort_order'], 'ix_mrq_pipeline_active_sort');
        });

        Schema::create('meeting_report_options', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('question_id')
                ->constrained('meeting_report_questions')
                ->cascadeOnDelete();

            $table->string('text', 255);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['question_id', 'sort_order'], 'ix_mro_question_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_report_options');
        Schema::dropIfExists('meeting_report_questions');
    }
};
