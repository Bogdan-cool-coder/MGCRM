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
        // Admin path: load ALL questions including drafts.
        // 'lesson' is eager-loaded to avoid N+1 in QuizAdminResource::ai_generation_status
        // (which reads lesson.content JSONB per row — fix for audit MINOR #15).
        $query = Quiz::query()->with(['allQuestions.options', 'lesson']);

        if ($lessonId !== null) {
            $query->where('lesson_id', $lessonId);
        }

        return $query->get();
    }

    public function show(Quiz $quiz): Quiz
    {
        // Admin path: load ALL questions including drafts + lesson for ai_generation_status.
        return $quiz->load(['allQuestions.options', 'lesson']);
    }

    /**
     * Student-facing: returns quiz with published (non-draft) questions only.
     */
    public function listByLesson(Lesson $lesson): ?Quiz
    {
        return Quiz::query()
            ->where('lesson_id', $lesson->id)
            ->with('questions.options')   // questions() already filters is_draft=false
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

            $correctOptions = $question->options->where('is_correct', true);

            $correctIds = $correctOptions
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            // Inline option texts so the review screen can render human-readable
            // correct answers without a second lookup.
            $correctOptionTexts = $correctOptions
                ->pluck('text')
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
                'question_text' => $question->text,
                'kind' => $question->kind instanceof \BackedEnum ? $question->kind->value : (string) $question->kind,
                'explanation' => $question->explanation,
                'selected_option_ids' => $selected,
                'correct_option_ids' => $correctIds,
                'correct_option_texts' => $correctOptionTexts,
                'is_correct' => $isCorrect,
            ];
        }

        // Division-by-zero guard: quiz with no questions → score 0 and not passed.
        // Pass gate uses the UNROUNDED ratio so rounding cannot inflate a borderline
        // score over the threshold (e.g. 79.5% must NOT pass an 80% gate).
        // The displayed score_pct is rounded for readability only.
        if ($totalPoints === 0) {
            return [
                'score_pct' => 0,
                'passed' => false,
                'n_correct' => 0,
                'annotated_answers' => $annotated,
            ];
        }

        $rawRatio = $earnedPoints / $totalPoints * 100;
        $scorePct = (int) round($rawRatio);
        // Strict gate: use the unrounded ratio to decide pass/fail.
        $passed = $rawRatio >= $passScorePct;

        return [
            'score_pct' => $scorePct,
            'passed' => $passed,
            'n_correct' => $nCorrect,
            'annotated_answers' => $annotated,
        ];
    }
}
