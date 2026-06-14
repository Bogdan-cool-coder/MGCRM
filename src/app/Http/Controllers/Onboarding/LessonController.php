<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Services\LessonService;
use App\Domain\Onboarding\Services\ProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompleteLessonRequest;
use App\Http\Requests\Onboarding\StoreLessonRequest;
use App\Http\Requests\Onboarding\UpdateLessonRequest;
use App\Http\Requests\Onboarding\UploadLessonFileRequest;
use App\Http\Resources\Onboarding\LessonProgressResource;
use App\Http\Resources\Onboarding\LessonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonController extends Controller
{
    public function __construct(
        private readonly LessonService $service,
        private readonly ProgressService $progressService,
    ) {}

    public function index(Request $request, CourseModule $module): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lesson::class);

        return LessonResource::collection(
            $this->service->listByModule($module)
        );
    }

    public function show(Request $request, CourseModule $module, Lesson $lesson): JsonResource
    {
        $this->authorize('view', $lesson);

        return LessonResource::make($lesson);
    }

    public function store(StoreLessonRequest $request, CourseModule $module): JsonResponse
    {
        $lesson = $this->service->create($module, $request->validated());

        return LessonResource::make($lesson)->response()->setStatusCode(201);
    }

    public function update(UpdateLessonRequest $request, CourseModule $module, Lesson $lesson): JsonResource
    {
        return LessonResource::make(
            $this->service->update($lesson, $request->validated())
        );
    }

    public function destroy(Request $request, CourseModule $module, Lesson $lesson): JsonResponse
    {
        $this->authorize('delete', $lesson);

        $this->service->delete($lesson);

        return response()->json(null, 204);
    }

    public function publish(Request $request, CourseModule $module, Lesson $lesson): JsonResource
    {
        $this->authorize('publish', $lesson);

        return LessonResource::make($this->service->publish($lesson));
    }

    public function unpublish(Request $request, CourseModule $module, Lesson $lesson): JsonResource
    {
        $this->authorize('publish', $lesson);

        return LessonResource::make($this->service->unpublish($lesson));
    }

    /**
     * Upload a PDF file for a kind=pdf lesson.
     * POST /api/admin/onboarding/lessons/{lesson}/upload
     * Updates lesson content.path with the stored path.
     */
    public function uploadFile(UploadLessonFileRequest $request, Lesson $lesson): JsonResource
    {
        $path = $this->service->storeFile($request->file('file'), $lesson->id);

        $lesson = $this->service->update($lesson, [
            'content' => ['path' => $path],
            'kind' => $lesson->kind->value,
        ]);

        return LessonResource::make($lesson);
    }

    /**
     * POST /api/onboarding/lessons/{lesson}/complete
     *
     * Mark a text/video/pdf lesson as done for the authenticated student.
     * Idempotent: repeated calls do not change completed_at, only update time_spent_seconds.
     * Returns 201 on first completion, 200 on subsequent calls.
     *
     * Quiz lessons cannot be completed via this endpoint — use quiz/start + submit.
     * Returns 403 if:
     *   - student has no active assignment for this course
     *   - lesson kind is quiz
     */
    public function complete(CompleteLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();

        // Ownership: resolve assignment for this user and course
        $lesson->load('module');
        $assignment = $this->progressService->resolveAssignment($user, $lesson->module->course_id);

        $timeSpentSeconds = (int) ($request->validated()['time_spent_seconds'] ?? 0);

        // Check if progress record already exists and has completed_at before the call
        $existingCompleted = LessonProgress::where('assignment_id', $assignment->id)
            ->where('lesson_id', $lesson->id)
            ->whereNotNull('completed_at')
            ->exists();

        // recordLessonDone throws LogicException for quiz lessons (→ caught as 422/500 without guard)
        // We convert the guard to a 403 per spec ("Use quiz submit")
        try {
            $progress = $this->progressService->recordLessonDone($assignment, $lesson->id, $timeSpentSeconds);
        } catch (\LogicException $e) {
            abort(403, $e->getMessage());
        }

        // 201 if newly completed (no prior completed_at), 200 if idempotent update
        return LessonProgressResource::make($progress)
            ->response()
            ->setStatusCode($existingCompleted ? 200 : 201);
    }
}
