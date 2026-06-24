<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quiz attempt result resource — returned after submit and on GET show.
 *
 * Each item in `answers[]` is a fully-resolved record:
 *   {question_id, question_text, kind, explanation, selected_option_ids,
 *    correct_option_ids, is_correct}
 *
 * This shape is produced by QuizService::computeScore (which now inlines
 * question_text / explanation / correct_option_ids into the annotated answers)
 * and stored in quiz_attempts.answers.
 *
 * For legacy open (not yet submitted) attempts the answers[] array may be []
 * or contain partial annotated items — FE guards on finished_at !== null.
 *
 * Showing correct answers after submit is intentional: this is an LMS for staff
 * training, not a certification exam — explanations improve learning outcomes.
 *
 * NOTE: question_details[] has been removed. All enrichment is inlined into
 * answers[] at score-compute time so FE can iterate a single array.
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
            // Annotated answers: each item has question_text, explanation,
            // correct_option_ids, selected_option_ids, is_correct — all inlined
            // by QuizService::computeScore at submission time.
            'answers' => $this->answers ?? [],
        ];
    }
}
