<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * QuizAttemptService — manages quiz attempt lifecycle.
 *
 * S3.2: start (attempt_number via lockForUpdate + idempotency).
 * S3.4: start extended (assignment_id filled under lock — PM-1),
 *        submit (scoring + assignment completion check).
 *
 * attempt_number pattern: identical to ContractNumberingService — lock rows,
 * compute MAX in PHP, increment. PG: lockForUpdate takes row-level lock.
 * SQLite :memory: (tests): lockForUpdate is a no-op on single connection but
 * functionally correct.
 */
class QuizAttemptService
{
    public function __construct(
        private readonly ProgressService $progressService,
    ) {}

    /**
     * Start a new quiz attempt, or return the existing open attempt (idempotent).
     *
     * S3.4 extension: fills assignment_id at creation.
     * PM-1: If an open attempt exists with assignment_id=null, update it inside
     *       the same lockForUpdate transaction to avoid race condition.
     *
     * Idempotency rule: if there is an open attempt (finished_at IS NULL) for
     * (user, quiz) — return it (with assignment_id updated if null).
     *
     * attempt_number: MAX+1 across ALL attempts (open + closed) for (user, quiz),
     * computed inside a lockForUpdate transaction.
     */
    public function start(Quiz $quiz, User $user, CourseAssignment $assignment): QuizAttempt
    {
        // Ownership: assignment must belong to this user and course
        if ($assignment->user_id !== $user->id) {
            abort(403, 'Assignment does not belong to this user.');
        }

        return DB::transaction(function () use ($quiz, $user, $assignment): QuizAttempt {
            // Idempotency: return open attempt if one already exists.
            // PM-1: if assignment_id is null on the open attempt, fill it inside
            // this same transaction (lockForUpdate prevents concurrent fills).
            $open = QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $user->id)
                ->whereNull('finished_at')
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                // PM-1: backfill assignment_id if it was null (created before S3.4)
                if ($open->assignment_id === null) {
                    $open->update(['assignment_id' => $assignment->id]);
                    $open->refresh();
                }

                return $open;
            }

            // lockForUpdate — same pattern as ContractNumberingService.
            // PG: does not allow FOR UPDATE with aggregates, so lock rows first,
            // then compute MAX in PHP.
            $rows = QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get(['attempt_number']);

            $maxNumber = $rows->max('attempt_number');
            $nextNumber = ($maxNumber === null ? 0 : $maxNumber) + 1;

            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'answers' => [],
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Submit a quiz attempt — score, annotate answers, update lesson progress.
     *
     * All work is done inside a single DB::transaction with lockForUpdate.
     *
     * @param  array<int, array{question_id: int, selected_option_ids: int[]}>  $answers
     *
     * @throws ConflictHttpException if attempt is already submitted (finished_at not null)
     */
    public function submit(QuizAttempt $attempt, array $answers, CourseAssignment $assignment): QuizAttempt
    {
        return DB::transaction(function () use ($attempt, $answers, $assignment): QuizAttempt {
            // lockForUpdate — protection against race / double-click submit
            $attempt = QuizAttempt::where('id', $attempt->id)->lockForUpdate()->firstOrFail();

            // Guard: already submitted
            if ($attempt->finished_at !== null) {
                throw new ConflictHttpException('Attempt already submitted.');
            }

            // Ownership check
            if ($attempt->user_id !== $assignment->user_id) {
                abort(403, 'Attempt does not belong to this user.');
            }

            // Eager load quiz with questions and options
            $attempt->load('quiz.questions.options');
            $quiz = $attempt->quiz;
            $questions = $quiz->questions;

            // Score computation via pure static function
            $result = QuizService::computeScore($questions, $answers, $quiz->pass_score_pct);

            // Update attempt with results
            $attempt->update([
                'score_pct' => $result['score_pct'],
                'passed' => $result['passed'],
                'answers' => $result['annotated_answers'],
                'finished_at' => now(),
                'assignment_id' => $assignment->id,
            ]);

            // If passed: create LessonProgress for the quiz-lesson
            if ($result['passed'] === true) {
                $quizLessonId = $this->findQuizLessonId($attempt->quiz_id);

                if ($quizLessonId !== null) {
                    LessonProgress::updateOrCreate(
                        ['assignment_id' => $assignment->id, 'lesson_id' => $quizLessonId],
                        ['completed_at' => now(), 'time_spent_seconds' => 0],
                    );
                }
            }

            // Transition pending → in_progress
            if ($assignment->status === AssignmentStatus::Pending) {
                $assignment->update(['status' => AssignmentStatus::InProgress]);
            }

            // Check if course is now complete
            $this->progressService->checkAndComplete($assignment->fresh());

            return $attempt->refresh()->load('quiz.questions.options');
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find the Lesson ID for a quiz-kind lesson referencing the given quiz_id.
     * Uses JSON content field: content->quiz_id.
     */
    private function findQuizLessonId(int $quizId): ?int
    {
        return Lesson::where('kind', LessonKind::Quiz->value)
            ->whereJsonContains('content->quiz_id', $quizId)
            ->value('id');
    }
}
