<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Services\AiTutorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AskTutorRequest;
use App\Http\Resources\Onboarding\AiTutorAnswerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AiTutorController — AI-тьютор для онбординга.
 *
 * Endpoints (student group):
 *   POST   /api/onboarding/lessons/{lesson}/ai-tutor           → ask
 *   GET    /api/onboarding/lessons/{lesson}/ai-tutor/history   → history
 *   DELETE /api/onboarding/lessons/{lesson}/ai-tutor/history   → clearHistory
 *
 * Authorization: LessonPolicy::view (admin/director) — students access via
 * the completion flow; any authenticated user can call the tutor if they have
 * an active assignment for this lesson's course.
 *
 * On AI failure: 503 with user-friendly message (not 500).
 */
class AiTutorController extends Controller
{
    public function __construct(
        private readonly AiTutorService $tutorService,
    ) {}

    /**
     * POST /api/onboarding/lessons/{lesson}/ai-tutor
     *
     * Synchronous multi-turn AI answer. On AI failure → 503.
     */
    public function ask(AskTutorRequest $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->tutorService->ask($user, $lesson, $request->string('question')->value());

            return (new AiTutorAnswerResource($result))->response();
        } catch (\Throwable $e) {
            Log::warning('AI tutor failed', [
                'lesson_id' => $lesson->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(
                ['error' => 'AI-тьютор временно недоступен. Попробуйте позже.'],
                503,
            );
        }
    }

    /**
     * GET /api/onboarding/lessons/{lesson}/ai-tutor/history
     *
     * Returns stored message history for the current user + lesson.
     */
    public function history(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $messages = $this->tutorService->getHistory($user, $lesson);

        return response()->json($messages);
    }

    /**
     * DELETE /api/onboarding/lessons/{lesson}/ai-tutor/history
     *
     * Clears message history for the current user + lesson.
     */
    public function clearHistory(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $this->tutorService->clearHistory($user, $lesson);

        return response()->json(null, 204);
    }
}
