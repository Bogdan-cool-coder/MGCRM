<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('module_id')
                ->constrained('course_modules')
                ->cascadeOnDelete()
                ->index('ix_lessons_module');
            $table->string('title', 255);
            $table->string('kind', 16);
            // PM-2: default '{}' (empty JSON object), NOT json_encode([]) which
            // would produce '[]' (array) — semantically wrong for an object field.
            $table->json('content')->default('{}');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(false)->index('ix_lessons_published');
            $table->timestamps();

            $table->index(['module_id', 'sort_order'], 'ix_lessons_module_sort');
            $table->index('kind', 'ix_lessons_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
