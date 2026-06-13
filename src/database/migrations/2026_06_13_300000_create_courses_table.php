<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('cover_image_path', 512)->nullable();
            $table->boolean('is_published')->default(false)->index('ix_courses_published');
            $table->unsignedSmallInteger('passing_score_pct')->default(80);
            $table->string('completion_policy', 16)->default('informational');
            $table->unsignedSmallInteger('deadline_days')->nullable();
            $table->integer('sort_order')->default(0)->index('ix_courses_sort');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->index('ix_courses_created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
