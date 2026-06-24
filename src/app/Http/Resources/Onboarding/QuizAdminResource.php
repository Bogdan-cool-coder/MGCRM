<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing quiz resource — includes is_correct + explanation on questions.
 * Returned by all /api/admin/onboarding/quizzes endpoints.
 *
 * ai_generation_status is sourced from Lesson.content JSONB.
 * BE writes 'done'|'pending'|'running'|'failed'; we normalise 'done' → 'completed'
 * so the FE useAiQuizGeneration poll resolves correctly.
 */
class QuizAdminResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Resolve ai_generation_status from the linked lesson's content JSONB.
        // Load lesson lazily if not already eager-loaded.
        $lesson = $this->resource->relationLoaded('lesson')
            ? $this->resource->lesson
            : $this->resource->lesson()->first();

        $rawStatus = data_get($lesson?->content ?? [], 'ai_generation_status');
        // Normalise 'done' → 'completed' to match the FE polling contract.
        $aiStatus = match ($rawStatus) {
            'done' => 'completed',
            'pending', 'running', 'failed', 'completed' => $rawStatus,
            default => 'idle',
        };

        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'description' => $this->description,
            'pass_score_pct' => $this->pass_score_pct,
            'time_limit_minutes' => $this->time_limit_minutes,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Surface AI generation status for HR/admin poller
            'ai_generation_status' => $aiStatus,
            // Admin path uses allQuestions (includes drafts); fall back to questions if loaded.
            'questions' => QuizQuestionAdminResource::collection(
                $this->resource->relationLoaded('allQuestions')
                    ? $this->resource->allQuestions
                    : $this->whenLoaded('questions')
            ),
        ];
    }
}
