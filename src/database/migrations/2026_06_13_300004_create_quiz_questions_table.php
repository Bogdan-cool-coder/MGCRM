<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('quiz_id')
                ->constrained('quizzes')
                ->cascadeOnDelete()
                ->index('ix_quiz_questions_quiz');
            $table->text('text');
            // 'single_choice' | 'multiple_choice'
            $table->string('kind', 16);
            $table->integer('sort_order')->default(0);
            // Shown to student AFTER submit only
            $table->text('explanation')->nullable();
            // Weight for score computation
            $table->unsignedSmallInteger('points')->default(1);
            $table->timestamps();

            $table->index(['quiz_id', 'sort_order'], 'ix_quiz_questions_quiz_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
