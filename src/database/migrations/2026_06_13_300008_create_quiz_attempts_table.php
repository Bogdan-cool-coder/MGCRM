<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('quiz_id')
                ->constrained('quizzes')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // Nullable until S3.4 populates it on start; FK ready for S3.3
            $table->foreignId('assignment_id')
                ->nullable()
                ->constrained('course_assignments')
                ->nullOnDelete();
            // MAX+1 per (user, quiz) — computed with lockForUpdate in service
            $table->unsignedSmallInteger('attempt_number');
            // null until submit
            $table->unsignedSmallInteger('score_pct')->nullable();
            // null until submit
            $table->boolean('passed')->nullable();
            // [{question_id, selected_option_ids, is_correct}] — server-annotated
            $table->json('answers')->default('[]');
            $table->timestamp('started_at');
            // null = open attempt
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['quiz_id', 'user_id'], 'ix_quiz_attempts_quiz_user');
            $table->index('user_id', 'ix_quiz_attempts_user');
            $table->index('assignment_id', 'ix_quiz_attempts_assignment');
        });

        // Partial UNIQUE on PG: only one open attempt per (quiz, user) at a time.
        // On SQLite :memory: (tests) this is not supported; service enforces it via
        // an idempotency check before insert.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_quiz_attempts_open ON quiz_attempts (quiz_id, user_id) WHERE finished_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
