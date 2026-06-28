<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Services\AiTutorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AskTutorRequest;
use App\Http\Resources\Onboarding\AiTutorAnswerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * AiTutorController — AI-тьютор для онбординга.
 *
 * Endpoints (student group):
 *   POST   /api/onboarding/lessons/{lesson}/ai-tutor           → ask
 *   GET    /api/onboarding/lessons/{lesson}/ai-tutor/history   → history
 *   DELETE /api/onboarding/lessons/{lesson}/ai-tutor/history   → clearHistory
 *
 * Authorization:
 *   - admin/director: always allowed.
 *   - other roles: must have an active (non-archived) CourseAssignment for the
 *     lesson's course. 403 otherwise (prevents accessing unassigned lessons).
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

        $this->authorizeAssignment($user, $lesson);

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

        $this->authorizeAssignment($user, $lesson);

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

        $this->authorizeAssignment($user, $lesson);

        $this->tutorService->clearHistory($user, $lesson);

        return response()->json(null, 204);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Guard: delegates admin-bypass to LessonPolicy::useTutor, then checks the
     * assignment for non-admin users. Aborts with 403 if the check fails.
     *
     * Two-step approach (Vizion pattern):
     *   1. Gate/Policy provides the admin-bypass rule (no inline role check).
     *   2. Controller resolves the DB assignment check for other roles.
     */
    private function authorizeAssignment(mixed $user, Lesson $lesson): void
    {
        // Policy gate — throws AuthorizationException (403) if denied.
        // LessonPolicy::useTutor grants admin/director unconditionally and
        // returns true for others, allowing step 2 below.
        Gate::authorize('useTutor', $lesson);

        // Fast-exit for admin/director (Policy already handled them).
        if ($user->can('onboarding.manage')) {
            return;
        }

        // Non-admin: must have an active (non-archived) assignment for the lesson's course.
        $module = $lesson->module;

        if ($module === null) {
            abort(403, 'Lesson is not attached to a module.');
        }

        $hasAssignment = CourseAssignment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $module->course_id)
            ->whereNotIn('status', ['archived'])
            ->exists();

        if (! $hasAssignment) {
            abort(403, 'You are not assigned to this course.');
        }
    }
}
