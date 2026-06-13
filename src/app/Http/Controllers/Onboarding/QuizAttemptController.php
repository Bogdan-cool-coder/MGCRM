<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Services\QuizAttemptService;
use App\Domain\Onboarding\Services\QuizService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Onboarding\QuizAttemptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    public function __construct(
        private readonly QuizAttemptService $service,
        private readonly QuizService $quizService,
    ) {}

    /**
     * POST /api/onboarding/lessons/{lesson}/quiz/start
     *
     * Idempotent: returns existing open attempt or creates a new one.
     * attempt_number: MAX+1 via lockForUpdate per (user, quiz).
     *
     * S3.4 will add:
     * - assignment_id population
     * - ownership check (user must have a valid assignment for this course)
     * - submit endpoint
     */
    public function start(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('create', QuizAttempt::class);

        $quiz = $this->quizService->listByLesson($lesson);

        if ($quiz === null) {
            abort(404, 'This lesson has no quiz attached.');
        }

        $attempt = $this->service->start($quiz, $request->user());

        return QuizAttemptResource::make($attempt)->response()->setStatusCode(201);
    }
}
