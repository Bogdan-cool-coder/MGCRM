<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Services\ProgressService;
use App\Domain\Onboarding\Services\QuizAttemptService;
use App\Domain\Onboarding\Services\QuizService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SubmitQuizAttemptRequest;
use App\Http\Resources\Onboarding\QuizAttemptResource;
use App\Http\Resources\Onboarding\QuizAttemptResultResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizAttemptController extends Controller
{
    public function __construct(
        private readonly QuizAttemptService $service,
        private readonly QuizService $quizService,
        private readonly ProgressService $progressService,
    ) {}

    /**
     * POST /api/onboarding/lessons/{lesson}/quiz/start
     *
     * Idempotent: returns existing open attempt or creates a new one.
     * attempt_number: MAX+1 via lockForUpdate per (user, quiz).
     *
     * S3.4: fills assignment_id under lock (PM-1).
     * Ownership: user must have an active assignment for this course.
     */
    public function start(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('create', QuizAttempt::class);

        $quiz = $this->quizService->listByLesson($lesson);

        if ($quiz === null) {
            abort(404, 'This lesson has no quiz attached.');
        }

        $user = $request->user();

        // Resolve assignment (ownership check — 403 if not assigned)
        $lesson->load('module');
        $assignment = $this->progressService->resolveAssignment($user, $lesson->module->course_id);

        $attempt = $this->service->start($quiz, $user, $assignment);

        return QuizAttemptResource::make($attempt)->response()->setStatusCode(201);
    }

    /**
     * POST /api/onboarding/quiz-attempts/{attempt}/submit
     *
     * Submit answers for an open quiz attempt.
     * Scores the attempt, records LessonProgress if passed, triggers course completion check.
     *
     * Returns 403 if attempt belongs to another user.
     * Returns 409 if attempt is already submitted (finished_at not null).
     */
    public function submit(SubmitQuizAttemptRequest $request, QuizAttempt $attempt): JsonResponse
    {
        $user = $request->user();

        // Ownership: attempt must belong to authenticated user
        if ($attempt->user_id !== $user->id) {
            abort(403, 'This attempt does not belong to you.');
        }

        // Resolve assignment from attempt (assignment_id was set at start)
        // If for any reason assignment_id is null (legacy open attempt), resolve by course
        if ($attempt->assignment_id !== null) {
            $assignment = CourseAssignment::findOrFail($attempt->assignment_id);
        } else {
            // Fallback: resolve via course of the quiz lesson
            $attempt->load('quiz.lesson.module');
            $courseId = $attempt->quiz?->lesson?->module?->course_id;
            if ($courseId === null) {
                abort(422, 'Cannot determine course for this attempt.');
            }
            $assignment = $this->progressService->resolveAssignment($user, $courseId);
        }

        $result = $this->service->submit($attempt, $request->validated()['answers'], $assignment);

        return QuizAttemptResultResource::make($result)->response()->setStatusCode(200);
    }

    /**
     * GET /api/onboarding/quiz-attempts/{attempt}
     *
     * View result of a submitted attempt.
     * Returns 403 if attempt belongs to another user.
     * If not yet submitted (finished_at null), returns the attempt without scores.
     */
    public function show(Request $request, QuizAttempt $attempt): JsonResource
    {
        $user = $request->user();

        if ($attempt->user_id !== $user->id) {
            abort(403, 'This attempt does not belong to you.');
        }

        // Load quiz with questions and options for explanation/correct_option_ids
        $attempt->load('quiz.questions.options');

        return QuizAttemptResultResource::make($attempt);
    }
}
