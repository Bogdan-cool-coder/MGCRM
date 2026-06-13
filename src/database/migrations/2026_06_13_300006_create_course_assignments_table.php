<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_date')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'user_id'], 'ux_course_assignments_course_user');
            $table->index('user_id', 'ix_course_assignments_user');
            $table->index('status', 'ix_course_assignments_status');
            $table->index('due_date', 'ix_course_assignments_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
