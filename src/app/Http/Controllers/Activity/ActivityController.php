<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Activity\ChangeStatusRequest;
use App\Http\Requests\Activity\StoreActivityRequest;
use App\Http\Requests\Activity\UpdateActivityRequest;
use App\Http\Resources\Activity\ActivityCardResource;
use App\Http\Resources\Activity\ActivityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin Activity controller (ARCHITECTURE.md §1). Visibility scope comes from the
 * ResolveVisibility middleware attribute (DealController::scope() pattern); all
 * logic, scoping and the status machine live in ActivityService.
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Activity::class);

        // Timeline mode: both target_type and target_id present. The service
        // gates target visibility and aggregates company → its deals (E7).
        if ($request->filled('target_type') && $request->filled('target_id')) {
            $activities = $this->service->timeline(
                (string) $request->query('target_type'),
                (int) $request->query('target_id'),
                $this->scope($request),
                $request->user(),
            );

            return ActivityResource::collection($activities);
        }

        $activities = $this->service->list(
            $request->query(),
            $this->scope($request),
            $request->user(),
            (int) $request->query('per_page', 25),
        );

        return ActivityResource::collection($activities);
    }

    public function store(StoreActivityRequest $request): JsonResource
    {
        $activity = $this->service->create($request->validated(), $request->user());

        return ActivityResource::make(
            $activity->load(['responsible:id,full_name', 'createdBy:id,full_name'])
        );
    }

    public function show(Request $request, Activity $activity): JsonResource
    {
        $this->authorize('view', $activity);

        return ActivityResource::make(
            $activity->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    public function update(UpdateActivityRequest $request, Activity $activity): JsonResource
    {
        $updated = $this->service->update($activity, $request->validated());

        return ActivityResource::make(
            $updated->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    public function destroy(Request $request, Activity $activity): Response
    {
        $this->authorize('delete', $activity);

        $this->service->delete($activity);

        return response()->noContent();
    }

    public function complete(Request $request, Activity $activity): JsonResource
    {
        $this->authorize('complete', $activity);

        $completed = $this->service->complete($activity, $request->user());

        return ActivityResource::make(
            $completed->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    public function reopen(Request $request, Activity $activity): JsonResource
    {
        $this->authorize('reopen', $activity);

        $reopened = $this->service->reopen($activity, $request->user());

        return ActivityResource::make(
            $reopened->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    public function status(ChangeStatusRequest $request, Activity $activity): JsonResource
    {
        $changed = $this->service->changeStatus(
            $activity,
            ActivityStatus::from($request->validated('status')),
            $request->user(),
            $request->safe()->only(['result_text']),
        );

        return ActivityResource::make(
            $changed->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    public function presets(Request $request, string $preset): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Activity::class);

        $activities = $this->service->presets(
            $preset,
            $this->scope($request),
            $request->user(),
            (int) $request->query('limit', 50),
        );

        return ActivityCardResource::collection($activities);
    }

    public function countsByPreset(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        return response()->json([
            'data' => $this->service->countsByPreset($this->scope($request), $request->user()),
        ]);
    }

    public function myOpenCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        return response()->json([
            'data' => ['count' => $this->service->myOpenCount($request->user())],
        ]);
    }

    // ---- Private ----

    private function scope(Request $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
