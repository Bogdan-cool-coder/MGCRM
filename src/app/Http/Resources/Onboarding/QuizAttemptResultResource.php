<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quiz attempt result resource — returned after submit (S3.4).
 * Includes annotated_answers with server-computed is_correct per question,
 * and question explanations (source of truth for S3.7 analytics).
 *
 * S3.2 creates this resource as structural contract for S3.4.
 */
class QuizAttemptResultResource extends JsonResource
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
            // Annotated answers include server-computed is_correct per question.
            // Populated by QuizAttemptService::submit in S3.4.
            'answers' => $this->answers,
        ];
    }
}
