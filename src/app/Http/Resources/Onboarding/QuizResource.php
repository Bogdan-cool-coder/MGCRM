<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing quiz resource — no is_correct, no explanation.
 * Returned by GET /api/onboarding/lessons/{lesson}/quiz.
 */
class QuizResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'pass_score_pct' => $this->pass_score_pct,
            'time_limit_minutes' => $this->time_limit_minutes,
            'questions' => QuizQuestionResource::collection(
                $this->whenLoaded('questions')
            ),
        ];
    }
}
