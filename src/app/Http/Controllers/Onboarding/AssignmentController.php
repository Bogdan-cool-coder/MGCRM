<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\AssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\BulkAssignRequest;
use App\Http\Requests\Onboarding\UpdateAssignmentRequest;
use App\Http\Resources\Onboarding\AssignmentDetailResource;
use App\Http\Resources\Onboarding\BulkAssignResultResource;
use App\Http\Resources\Onboarding\CourseAssignmentResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin/director assignment management.
 *
 * Routes (prefix: admin/onboarding):
 *   GET    /assignments            — list with filters
 *   POST   /assignments            — bulk-assign
 *   GET    /assignments/{id}       — detail with progress
 *   PATCH  /assignments/{id}       — update due_date / status→archived
 *   DELETE /assignments/{id}       — delete (409 if progress exists)
 *   POST   /assignments/{id}/archive — archive
 */
class AssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourseAssignment::class);

        $paginator = $this->service->listForAdmin(
            $request->query(),
            (int) $request->query('per_page', 25),
        );

        return CourseAssignmentResource::collection($paginator);
    }

    public function show(Request $request, CourseAssignment $assignment): JsonResource
    {
        $this->authorize('view', $assignment);

        $assignment->load(['course.modules.lessons', 'user', 'assignedBy']);

        return AssignmentDetailResource::make($assignment);
    }

    public function store(BulkAssignRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dueDate = isset($validated['due_date'])
            ? Carbon::parse($validated['due_date'])->endOfDay()
            : null;

        $result = $this->service->bulkAssign(
            userIds: $validated['user_ids'],
            courseId: (int) $validated['course_id'],
            assignedByUserId: $request->user()->id,
            dueDate: $dueDate,
        );

        return BulkAssignResultResource::make($result)->response()->setStatusCode(201);
    }

    public function update(UpdateAssignmentRequest $request, CourseAssignment $assignment): JsonResource
    {
        $validated = $request->validated();

        // Convert due_date to end-of-day Carbon if provided
        if (isset($validated['due_date'])) {
            $validated['due_date'] = Carbon::parse($validated['due_date'])->endOfDay();
        }

        $updated = $this->service->update($assignment, $validated);

        return CourseAssignmentResource::make($updated->load('course'));
    }

    public function destroy(Request $request, CourseAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $this->service->delete($assignment);

        return response()->json(null, 204);
    }

    public function archive(Request $request, CourseAssignment $assignment): JsonResource
    {
        $this->authorize('update', $assignment);

        $this->service->archive($assignment);

        return CourseAssignmentResource::make($assignment->refresh()->load('course'));
    }
}
