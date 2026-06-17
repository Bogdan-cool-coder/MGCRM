<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3.5: AI-тьютор сессии.
 *
 * One record per (user_id, lesson_id) pair. Messages stored as JSONB array
 * [{role: user|assistant, content: string, created_at: iso_string}].
 * Truncated to 10 pairs (20 messages) on each append.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_ai_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->jsonb('messages')->default('[]');
            $table->timestamps();

            // One session per user+lesson pair — append messages, never create a new row.
            $table->unique(['user_id', 'lesson_id'], 'uq_oas_user_lesson');

            // Index for history lookups by user.
            $table->index('user_id', 'ix_oas_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_ai_sessions');
    }
};
