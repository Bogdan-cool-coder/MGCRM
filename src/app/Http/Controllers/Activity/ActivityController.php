<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Models\Deal;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Activity\ChangeStatusRequest;
use App\Http\Requests\Activity\CompleteActivityRequest;
use App\Http\Requests\Activity\RescheduleActivityRequest;
use App\Http\Requests\Activity\StoreActivityRequest;
use App\Http\Requests\Activity\StoreBulkActivityRequest;
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

    /**
     * POST /api/activities/bulk — create one activity/task on EACH of several
     * deals (board toolbar mass task). Every target deal is authorised up front
     * (all-or-nothing 403 — no partial create across a foreign card), then each
     * activity is created through ActivityService::create so the per-deal
     * task_types gate, department stamping and events all still fire. Returns the
     * count created.
     */
    public function bulkStore(StoreBulkActivityRequest $request): JsonResponse
    {
        $dealIds = $request->dealIds();
        $deals = Deal::query()->whereIn('id', $dealIds)->get();

        if ($deals->count() !== count(array_unique($dealIds))) {
            abort(403, 'One or more deals are not accessible.');
        }

        foreach ($deals as $deal) {
            if (! $request->user()->can('view', $deal)) {
                abort(403, 'One or more deals are not accessible.');
            }
        }

        $payload = $request->taskPayload();
        $created = 0;

        foreach ($deals as $deal) {
            $this->service->create(array_merge($payload, [
                'target_type' => ActivityTargetType::Deal->value,
                'target_id' => $deal->id,
            ]), $request->user());

            $created++;
        }

        return response()->json(['data' => ['created' => $created]]);
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
        $updated = $this->service->update($activity, $request->validated(), $request->user());

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

    public function complete(CompleteActivityRequest $request, Activity $activity): JsonResource
    {
        $completed = $this->service->complete(
            $activity,
            $request->user(),
            $request->validated('result_text'),
        );

        return ActivityResource::make(
            $completed->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
        );
    }

    /**
     * Quick due-date shift from the task list — POST EXACTLY ONE of {preset}
     * (tomorrow / +1d / +1w / next_monday, resolved in the operational timezone)
     * or {due_at} (an explicit absolute date from the picker). Only moves due_at:
     * status and engagement are untouched.
     */
    public function reschedule(RescheduleActivityRequest $request, Activity $activity): JsonResource
    {
        $dueAt = $request->date('due_at');

        $rescheduled = $this->service->reschedule(
            $activity,
            $request->validated('preset'),
            $dueAt,
        );

        return ActivityResource::make(
            $rescheduled->load(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
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
            // The FilterPanel feeds the same query params to the preset endpoint as
            // to the flat list (D2): they narrow within the preset.
            $request->query(),
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

    /**
     * Personal task board (Сделки — ТЗ §4): the current user's open task-like
     * activities grouped into fixed urgency buckets (overdue / today / tomorrow /
     * this_week / next_week / later). Scoped to the authenticated user (responsible OR
     * creator) — no visibility scope, this is "my work". Optional ?q= filters by
     * title/body. Each bucket is rendered with the lightweight card resource.
     */
    public function myBoard(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        $search = $request->query('q');
        $buckets = $this->service->myBoard(
            $request->user(),
            is_string($search) ? $search : null,
        );

        $payload = [];
        foreach ($buckets as $key => $activities) {
            $payload[$key] = ActivityCardResource::collection(collect($activities));
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Team task board (M4/M5): the same urgency-bucket shape as myBoard, but scoped
     * to the authenticated manager's DEPARTMENT subtree instead of "my work" — a
     * director/manager sees the open tasks of every user under them. Gated to
     * admin/director/manager via ActivityPolicy::viewTeamBoard (others 403). The
     * department is inferred from the caller, never passed in. The board accepts
     * the SAME filter params as the personal my-board / flat list
     * (ActivityService::applyListFilters): responsible_id, q, kind, status,
     * priority and the due_from/due_to range — each narrows WITHIN the department
     * subtree so «Команда» filters exactly like «Мои задачи».
     */
    public function teamBoard(Request $request): JsonResponse
    {
        $this->authorize('viewTeamBoard', Activity::class);

        $buckets = $this->service->teamBoard(
            $request->user(),
            $request->only(['responsible_id', 'q', 'kind', 'status', 'priority', 'due_from', 'due_to']),
        );

        $payload = [];
        foreach ($buckets as $key => $activities) {
            $payload[$key] = ActivityCardResource::collection(collect($activities));
        }

        return response()->json(['data' => $payload]);
    }

    public function myOpenCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        return response()->json([
            'data' => ['count' => $this->service->myOpenCount($this->scope($request), $request->user())],
        ]);
    }

    // ---- Private ----

    private function scope(Request $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
