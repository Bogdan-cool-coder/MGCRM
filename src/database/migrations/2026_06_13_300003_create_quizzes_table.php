<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete()
                ->index('ix_quizzes_lesson');
            $table->string('title', 255);
            $table->text('description')->nullable();
            // 0-100; service-level validation enforces bounds
            $table->unsignedSmallInteger('pass_score_pct')->default(80);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->index('ix_quizzes_created_by');
            $table->timestamps();

            // DDL-level guarantee: one quiz per lesson
            $table->unique('lesson_id', 'uq_quizzes_lesson');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
