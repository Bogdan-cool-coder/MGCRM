<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Services\LessonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreLessonRequest;
use App\Http\Requests\Onboarding\UpdateLessonRequest;
use App\Http\Requests\Onboarding\UploadLessonFileRequest;
use App\Http\Resources\Onboarding\LessonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonController extends Controller
{
    public function __construct(
        private readonly LessonService $service,
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
}
