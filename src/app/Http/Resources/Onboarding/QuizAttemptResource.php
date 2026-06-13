<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quiz attempt resource — returned by start and for in-progress attempts.
 * Does not include annotated_answers (those appear only in QuizAttemptResultResource).
 */
class QuizAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'user_id' => $this->user_id,
            'assignment_id' => $this->assignment_id,
            'attempt_number' => $this->attempt_number,
            'score_pct' => $this->score_pct,
            'passed' => $this->passed,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
