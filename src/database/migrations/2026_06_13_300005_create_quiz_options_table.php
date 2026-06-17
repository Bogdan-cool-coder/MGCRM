<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_options', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('question_id')
                ->constrained('quiz_questions')
                ->cascadeOnDelete()
                ->index('ix_quiz_options_question');
            $table->string('text', 512);
            // NOT returned to students until after submit
            $table->boolean('is_correct')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'sort_order'], 'ix_quiz_options_question_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_options');
    }
};
