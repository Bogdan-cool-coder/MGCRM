<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Services\ProgressService;
use App\Domain\Onboarding\Services\QuizService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreQuizRequest;
use App\Http\Requests\Onboarding\UpdateQuizRequest;
use App\Http\Resources\Onboarding\QuizAdminResource;
use App\Http\Resources\Onboarding\QuizResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizController extends Controller
{
    public function __construct(
        private readonly QuizService $service,
        private readonly ProgressService $progressService,
    ) {}

    /**
     * GET /api/admin/onboarding/quizzes?lesson_id=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Quiz::class);

        $lessonId = $request->query('lesson_id') ? (int) $request->query('lesson_id') : null;

        return QuizAdminResource::collection(
            $this->service->list($lessonId)
        );
    }

    /**
     * GET /api/admin/onboarding/quizzes/{quiz}
     */
    public function show(Request $request, Quiz $quiz): JsonResource
    {
        $this->authorize('view', $quiz);

        return QuizAdminResource::make($this->service->show($quiz));
    }

    /**
     * POST /api/admin/onboarding/quizzes
     */
    public function store(StoreQuizRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $lesson = Lesson::findOrFail($validated['lesson_id']);

        $quiz = $this->service->create(
            array_merge($validated, ['created_by_user_id' => $request->user()->id]),
            $lesson,
        );

        return QuizAdminResource::make($quiz)->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/onboarding/quizzes/{quiz}
     */
    public function update(UpdateQuizRequest $request, Quiz $quiz): JsonResource
    {
        return QuizAdminResource::make(
            $this->service->update($quiz, $request->validated())
        );
    }

    /**
     * DELETE /api/admin/onboarding/quizzes/{quiz}
     */
    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('delete', $quiz);

        $this->service->delete($quiz);

        return response()->json(null, 204);
    }

    /**
     * GET /api/onboarding/lessons/{lesson}/quiz
     * Student-facing: no is_correct, no explanation.
     * S3.4: ownership check — 403 if user has no active assignment for this course.
     */
    public function showForStudent(Request $request, Lesson $lesson): JsonResource
    {
        // Ownership: resolve assignment (403 if not assigned)
        $lesson->load('module');
        $this->progressService->resolveAssignment($request->user(), $lesson->module->course_id);

        $quiz = $this->service->listByLesson($lesson);

        if ($quiz === null) {
            abort(404, 'This lesson has no quiz attached.');
        }

        return QuizResource::make($quiz);
    }
}
