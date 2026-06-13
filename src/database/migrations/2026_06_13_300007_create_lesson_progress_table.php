<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('course_assignments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamps();

            $table->unique(['assignment_id', 'lesson_id'], 'ux_lesson_progress_assignment_lesson');
            $table->index('lesson_id', 'ix_lesson_progress_lesson');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
