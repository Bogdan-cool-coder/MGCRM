<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Services\CourseService;
use App\Domain\Onboarding\Services\ModuleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ReorderRequest;
use App\Http\Requests\Onboarding\StoreCourseModuleRequest;
use App\Http\Requests\Onboarding\UpdateCourseModuleRequest;
use App\Http\Resources\Onboarding\CourseModuleResource;
use App\Http\Resources\Onboarding\LessonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseModuleController extends Controller
{
    public function __construct(
        private readonly ModuleService $moduleService,
        private readonly CourseService $courseService,
    ) {}

    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourseModule::class);

        return CourseModuleResource::collection(
            $this->moduleService->listByCourse($course)
        );
    }

    public function store(StoreCourseModuleRequest $request, Course $course): JsonResponse
    {
        $module = $this->moduleService->create($course, $request->validated());

        return CourseModuleResource::make($module)->response()->setStatusCode(201);
    }

    public function update(UpdateCourseModuleRequest $request, Course $course, CourseModule $module): JsonResource
    {
        return CourseModuleResource::make(
            $this->moduleService->update($module, $request->validated())
        );
    }

    public function destroy(Request $request, Course $course, CourseModule $module): JsonResponse
    {
        $this->authorize('delete', $module);

        $this->moduleService->delete($module);

        return response()->json(null, 204);
    }

    /**
     * Reorder modules within a course.
     * POST /api/admin/onboarding/courses/{course}/modules/reorder
     */
    public function reorder(ReorderRequest $request, Course $course): AnonymousResourceCollection
    {
        $this->authorize('update', $course);

        return CourseModuleResource::collection(
            $this->courseService->reorderModules($course, $request->validated('order'))
        );
    }

    /**
     * Reorder lessons within a module.
     * POST /api/admin/onboarding/modules/{module}/lessons/reorder
     */
    public function reorderLessons(ReorderRequest $request, CourseModule $module): AnonymousResourceCollection
    {
        $this->authorize('update', $module);

        return LessonResource::collection(
            $this->moduleService->reorderLessons($module, $request->validated('order'))
        );
    }
}
