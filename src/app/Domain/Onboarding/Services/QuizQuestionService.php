<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Enums\QuestionKind;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QuizQuestionService — CRUD + reorder.
 *
 * sort_order on create: MAX+1 inside quiz via lockForUpdate (same pattern as
 * ModuleService and LessonService — PG does not allow FOR UPDATE with aggregates).
 * Reorder: dense 1..N from array position, foreign-question check.
 */
class QuizQuestionService
{
    /** @return Collection<int, QuizQuestion> */
    public function listByQuiz(Quiz $quiz): Collection
    {
        return QuizQuestion::query()
            ->where('quiz_id', $quiz->id)
            ->with('options')
            ->orderBy('sort_order')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Quiz $quiz, array $data): QuizQuestion
    {
        return DB::transaction(function () use ($quiz, $data): QuizQuestion {
            // MAX+1 sort_order — same PG pattern as ModuleService/LessonService
            $max = QuizQuestion::query()
                ->where('quiz_id', $quiz->id)
                ->lockForUpdate()
                ->get(['sort_order'])
                ->max('sort_order');

            return QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'text' => $data['text'],
                'kind' => QuestionKind::from($data['kind']),
                'sort_order' => ($max ?? 0) + 1,
                'explanation' => $data['explanation'] ?? null,
                'points' => $data['points'] ?? 1,
                'is_draft' => $data['is_draft'] ?? false,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(QuizQuestion $question, array $data): QuizQuestion
    {
        $updates = [];

        if (isset($data['text'])) {
            $updates['text'] = $data['text'];
        }

        if (isset($data['kind'])) {
            $updates['kind'] = QuestionKind::from($data['kind']);
        }

        if (array_key_exists('explanation', $data)) {
            $updates['explanation'] = $data['explanation'];
        }

        if (isset($data['points'])) {
            $updates['points'] = (int) $data['points'];
        }

        // HR-review gate: HR sets is_draft=false to approve AI-generated draft questions.
        if (array_key_exists('is_draft', $data)) {
            $updates['is_draft'] = (bool) $data['is_draft'];
        }

        $question->update($updates);
        $question->refresh();

        return $question;
    }

    public function delete(QuizQuestion $question): void
    {
        // cascadeOnDelete removes options
        $question->delete();
    }

    /**
     * Bulk reorder questions within a quiz.
     * Dense 1..N from array position. Rejects IDs not belonging to quiz.
     *
     * @param  list<array{id: int}>  $order
     * @return Collection<int, QuizQuestion>
     */
    public function reorder(Quiz $quiz, array $order): Collection
    {
        return DB::transaction(function () use ($quiz, $order): Collection {
            $questions = QuizQuestion::query()
                ->where('quiz_id', $quiz->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;
            foreach ($order as $item) {
                $id = (int) $item['id'];
                $question = $questions->get($id);

                if ($question === null) {
                    throw ValidationException::withMessages([
                        'questions' => 'A question in the payload does not belong to this quiz.',
                    ])->status(422);
                }

                $question->update(['sort_order' => $position]);
                $position++;
            }

            return QuizQuestion::query()
                ->where('quiz_id', $quiz->id)
                ->with('options')
                ->orderBy('sort_order')
                ->get();
        });
    }
}
