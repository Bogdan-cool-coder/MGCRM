<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QuizOptionService — CRUD + reorder for quiz answer options.
 *
 * sort_order on create: MAX+1 inside question via lockForUpdate.
 * is_correct: no DDL constraint on uniqueness — scoring engine handles it.
 */
class QuizOptionService
{
    /** @return Collection<int, QuizOption> */
    public function listByQuestion(QuizQuestion $question): Collection
    {
        return QuizOption::query()
            ->where('question_id', $question->id)
            ->orderBy('sort_order')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(QuizQuestion $question, array $data): QuizOption
    {
        return DB::transaction(function () use ($question, $data): QuizOption {
            $max = QuizOption::query()
                ->where('question_id', $question->id)
                ->lockForUpdate()
                ->get(['sort_order'])
                ->max('sort_order');

            return QuizOption::create([
                'question_id' => $question->id,
                'text' => $data['text'],
                'is_correct' => $data['is_correct'] ?? false,
                'sort_order' => ($max ?? 0) + 1,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(QuizOption $option, array $data): QuizOption
    {
        $updates = [];

        if (isset($data['text'])) {
            $updates['text'] = $data['text'];
        }

        if (array_key_exists('is_correct', $data)) {
            $updates['is_correct'] = (bool) $data['is_correct'];
        }

        if (isset($data['sort_order'])) {
            $updates['sort_order'] = (int) $data['sort_order'];
        }

        $option->update($updates);
        $option->refresh();

        return $option;
    }

    public function delete(QuizOption $option): void
    {
        $option->delete();
    }

    /**
     * Bulk reorder options within a question.
     * Dense 1..N from array position. Rejects IDs not belonging to question.
     *
     * @param  list<array{id: int}>  $order
     * @return Collection<int, QuizOption>
     */
    public function reorder(QuizQuestion $question, array $order): Collection
    {
        return DB::transaction(function () use ($question, $order): Collection {
            $options = QuizOption::query()
                ->where('question_id', $question->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;
            foreach ($order as $item) {
                $id = (int) $item['id'];
                $option = $options->get($id);

                if ($option === null) {
                    throw ValidationException::withMessages([
                        'options' => 'An option in the payload does not belong to this question.',
                    ])->status(422);
                }

                $option->update(['sort_order' => $position]);
                $position++;
            }

            return QuizOption::query()
                ->where('question_id', $question->id)
                ->orderBy('sort_order')
                ->get();
        });
    }
}
