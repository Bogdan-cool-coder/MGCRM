<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QuizService — CRUD + lesson binding + score computation.
 *
 * Business rules:
 * - Quiz is always 1:1 with a Lesson.kind=quiz.
 * - Create: attaches quiz to lesson via LessonService::attachQuiz (inside transaction).
 * - Delete: only allowed if lesson is not published; clears lesson.content.quiz_id.
 * - lesson_id is immutable after creation.
 * - computeScore: static pure function; exact set match; partial NOT credited.
 */
class QuizService
{
    public function __construct(
        private readonly LessonService $lessonService,
    ) {}

    /**
     * List quizzes, optionally filtered by lesson.
     *
     * @return Collection<int, Quiz>
     */
    public function list(?int $lessonId = null): Collection
    {
        $query = Quiz::query()->with('questions.options');

        if ($lessonId !== null) {
            $query->where('lesson_id', $lessonId);
        }

        return $query->get();
    }

    public function show(Quiz $quiz): Quiz
    {
        return $quiz->load('questions.options');
    }

    public function listByLesson(Lesson $lesson): ?Quiz
    {
        return Quiz::query()
            ->where('lesson_id', $lesson->id)
            ->with('questions.options')
            ->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, Lesson $lesson): Quiz
    {
        return DB::transaction(function () use ($data, $lesson): Quiz {
            // Validate binding constraints BEFORE Quiz::create() to produce a
            // human-friendly 422 instead of a raw DDL UNIQUE violation from PG/SQLite.
            $this->lessonService->assertCanAttachQuiz($lesson);

            // pass_score_pct: from data or default from course
            $passScore = $data['pass_score_pct']
                ?? $lesson->module?->course?->passing_score_pct
                ?? 80;

            $quiz = Quiz::create([
                'lesson_id' => $lesson->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'pass_score_pct' => (int) $passScore,
                'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);

            // Closes S3.1 publish-guard: sets lesson.content.quiz_id
            $this->lessonService->attachQuiz($lesson, $quiz);

            return $quiz->load('questions.options');
        });
    }

    /**
     * Update quiz meta-fields only (lesson_id is immutable).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quiz $quiz, array $data): Quiz
    {
        $payload = [];

        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description']; // null clears the field
        }

        if (array_key_exists('pass_score_pct', $data)) {
            $payload['pass_score_pct'] = $data['pass_score_pct'];
        }

        if (array_key_exists('time_limit_minutes', $data)) {
            $payload['time_limit_minutes'] = $data['time_limit_minutes']; // null clears the field
        }

        if ($payload !== []) {
            $quiz->update($payload);
        }

        $quiz->refresh();

        return $quiz->load('questions.options');
    }

    /**
     * Delete a quiz.
     * Guard: lesson must NOT be published.
     */
    public function delete(Quiz $quiz): void
    {
        DB::transaction(function () use ($quiz): void {
            $lesson = Lesson::find($quiz->lesson_id);

            if ($lesson?->is_published) {
                throw ValidationException::withMessages([
                    'quiz' => 'Unpublish the lesson before deleting its quiz.',
                ])->status(422);
            }

            if ($lesson !== null) {
                $this->lessonService->detachQuiz($lesson);
            }

            // cascadeOnDelete will remove questions → options
            $quiz->delete();
        });
    }

    /**
     * Compute quiz score.
     *
     * Pure static function — no DB calls, fully unit-testable.
     *
     * Scoring rule: exact set match — set(selected) === set(correct).
     * Partial credit (subset) is NOT awarded.
     *
     * @param  Collection<int, QuizQuestion>  $questions  with loaded options
     * @param  array<int, array{question_id: int, selected_option_ids: int[]}>  $answers
     * @return array{score_pct: int, passed: bool, n_correct: int, annotated_answers: list<array{question_id: int, selected_option_ids: int[], is_correct: bool}>}
     */
    public static function computeScore(
        Collection $questions,
        array $answers,
        int $passScorePct,
    ): array {
        $answerMap = collect($answers)->keyBy('question_id');

        $totalPoints = 0;
        $earnedPoints = 0;
        $nCorrect = 0;
        $annotated = [];

        foreach ($questions as $question) {
            $totalPoints += $question->points;

            $correctIds = $question->options
                ->where('is_correct', true)
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $answer = $answerMap->get($question->id);
            $selected = $answer
                ? collect($answer['selected_option_ids'])->sort()->values()->all()
                : [];

            $isCorrect = $selected === $correctIds;

            if ($isCorrect) {
                $earnedPoints += $question->points;
                $nCorrect++;
            }

            $annotated[] = [
                'question_id' => $question->id,
                'selected_option_ids' => $selected,
                'is_correct' => $isCorrect,
            ];
        }

        // Division-by-zero guard: quiz with no questions → score 0
        $scorePct = $totalPoints > 0
            ? (int) round($earnedPoints / $totalPoints * 100)
            : 0;

        return [
            'score_pct' => $scorePct,
            'passed' => $scorePct >= $passScorePct,
            'n_correct' => $nCorrect,
            'annotated_answers' => $annotated,
        ];
    }
}
