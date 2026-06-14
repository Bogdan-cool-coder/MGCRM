<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quiz attempt result resource — returned after submit and on GET show.
 * When finished_at is set, includes annotated_answers with is_correct per question,
 * plus question_details with explanation and correct_option_ids.
 *
 * Showing correct answers after submit is intentional: this is an LMS for staff
 * training, not a certification exam — explanations improve learning outcomes.
 */
class QuizAttemptResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $questionDetails = null;

        // Enrich with explanation and correct_option_ids when attempt is submitted
        if ($this->finished_at !== null && $this->relationLoaded('quiz')) {
            $quiz = $this->quiz;

            if ($quiz !== null && $quiz->relationLoaded('questions')) {
                $questionDetails = $quiz->questions->map(function ($question) {
                    $correctOptionIds = $question->options
                        ->where('is_correct', true)
                        ->pluck('id')
                        ->values()
                        ->all();

                    return [
                        'question_id' => $question->id,
                        'explanation' => $question->explanation,
                        'correct_option_ids' => $correctOptionIds,
                    ];
                })->values()->all();
            }
        }

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
            'answers' => $this->answers,
            // question_details: present only after submission
            'question_details' => $questionDetails,
        ];
    }
}
