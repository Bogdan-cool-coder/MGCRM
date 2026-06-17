<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete()
                ->index('ix_course_modules_course');
            $table->string('title', 255);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Composite index for ordered listing within a course.
            // No DDL UNIQUE on (course_id, sort_order) — dense normalisation is
            // done in the service layer (reorder sets 1..N); intermediate CRUD
            // states would violate a hard UNIQUE constraint.
            $table->index(['course_id', 'sort_order'], 'ix_course_modules_course_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_modules');
    }
};
