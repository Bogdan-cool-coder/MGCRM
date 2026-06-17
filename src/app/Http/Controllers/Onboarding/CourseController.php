<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Services\CourseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreCourseRequest;
use App\Http\Requests\Onboarding\UpdateCourseRequest;
use App\Http\Resources\Onboarding\CourseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseController extends Controller
{
    public function __construct(
        private readonly CourseService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Course::class);

        return CourseResource::collection(
            $this->service->list($request->query(), (int) $request->query('per_page', 25))
        );
    }

    public function show(Request $request, Course $course): JsonResource
    {
        $this->authorize('view', $course);

        return CourseResource::make($course->load('modules.lessons'));
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by_user_id'] = $request->user()->id;

        $course = $this->service->create($data);

        return CourseResource::make($course)->response()->setStatusCode(201);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResource
    {
        return CourseResource::make(
            $this->service->update($course, $request->validated())
        );
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $this->authorize('delete', $course);

        $this->service->delete($course);

        return response()->json(null, 204);
    }

    public function publish(Request $request, Course $course): JsonResource
    {
        $this->authorize('publish', $course);

        return CourseResource::make($this->service->publish($course));
    }

    public function unpublish(Request $request, Course $course): JsonResource
    {
        $this->authorize('publish', $course);

        return CourseResource::make($this->service->unpublish($course));
    }
}
