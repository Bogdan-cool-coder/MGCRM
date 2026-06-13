<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\AssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Onboarding\AssignmentDetailResource;
use App\Http\Resources\Onboarding\MyCoursesResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing course view endpoints.
 *
 * Routes (prefix: onboarding):
 *   GET /my-courses           — learner's own assignments + progress_pct
 *   GET /assignments/{id}     — assignment detail with lesson completion flags (IDOR-protected)
 */
class StudentCourseController extends Controller
{
    public function __construct(
        private readonly AssignmentService $service,
    ) {}

    /**
     * GET /api/onboarding/my-courses
     * Returns the authenticated user's own course assignments with progress.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = $this->service->listForUser($request->user()->id);

        return MyCoursesResource::collection($assignments);
    }

    /**
     * GET /api/onboarding/assignments/{assignment}
     * Returns full assignment detail with lesson completion flags.
     * IDOR: learner may only view their own; admin/director may view any.
     */
    public function show(CourseAssignment $assignment): JsonResource
    {
        $this->authorize('view', $assignment);

        $assignment->load(['course.modules.lessons']);

        return AssignmentDetailResource::make($assignment);
    }
}
