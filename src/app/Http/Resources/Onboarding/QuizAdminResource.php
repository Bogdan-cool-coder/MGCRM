<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing quiz resource — includes is_correct + explanation on questions.
 * Returned by all /api/admin/onboarding/quizzes endpoints.
 */
class QuizAdminResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
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
            // Admin path uses allQuestions (includes drafts); fall back to questions if loaded.
            'questions' => QuizQuestionAdminResource::collection(
                $this->resource->relationLoaded('allQuestions')
                    ? $this->resource->allQuestions
                    : $this->whenLoaded('questions')
            ),
        ];
    }
}
