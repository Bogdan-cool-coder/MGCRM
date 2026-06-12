<?php

declare(strict_types=1);

namespace App\Domain\Activity\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityAssigned;
use App\Domain\Activity\Events\ActivityCreated;
use App\Domain\Activity\Events\ActivityStatusChanged;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ActivityService — the core of the Activity domain. All Activity CRUD, the
 * status machine, presets/counters and timeline live here (ARCHITECTURE.md §1).
 * Visibility scoping mirrors DealService::scopedQuery exactly so the policy and
 * the query layer can never drift apart (E6).
 *
 * Notes on safety boundaries:
 *  - status is NEVER mutated via update() — only changeStatus()/complete()/reopen().
 *  - the polymorphic target is validated AND gated for visibility on create (E5).
 *  - the overdue/today/this_week predicates are single-source between presets and
 *    countsByPreset (fixes the old badge≠list bug, E4).
 */
class ActivityService
{
    private const PRESETS = ['my_tasks', 'my_orders', 'overdue', 'today', 'this_week', 'pinned'];

    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

    /**
     * Paginated, visibility-scoped activity list. When target_type/target_id are
     * supplied it acts as the timeline query for that entity (E7 handles the
     * company aggregate separately via timeline()).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, VisibilityScope $scope, User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scopedQuery($scope, $user)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
            ->when(isset($filters['target_type']), fn (Builder $q) => $q->where('target_type', $filters['target_type']))
            ->when(isset($filters['target_id']), fn (Builder $q) => $q->where('target_id', (int) $filters['target_id']))
            ->when(! empty($filters['kind']), fn (Builder $q) => $q->whereIn('kind', (array) $filters['kind']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->whereIn('status', (array) $filters['status']))
            ->when(! empty($filters['priority']), fn (Builder $q) => $q->whereIn('priority', (array) $filters['priority']))
            ->when(isset($filters['due_from']), fn (Builder $q) => $q->where('due_at', '>=', $filters['due_from']))
            ->when(isset($filters['due_to']), fn (Builder $q) => $q->where('due_at', '<=', $filters['due_to']))
            ->when(isset($filters['q']), fn (Builder $q) => $q->where('title', 'like', '%'.$filters['q'].'%'))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Timeline for a single target entity. For a company this aggregates the
     * company's own activities AND the activities of all its (visible) deals
     * (E7). Target visibility is gated first to block IDOR on the feed.
     *
     * Returns a paginator (not a bare Collection) so the resource collection
     * always carries a `meta.total` envelope — the frontend timeline reads
     * res.meta.total and would crash on a meta-less payload (BUG-5/5b).
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function timeline(string $targetType, int $targetId, VisibilityScope $scope, User $user, int $perPage = 100): LengthAwarePaginator
    {
        $type = ActivityTargetType::tryFrom($targetType);

        if ($type === null) {
            throw ValidationException::withMessages([
                'target_type' => "Unsupported target type: {$targetType}.",
            ]);
        }

        // Gate visibility of the target itself (no peeking into a foreign feed).
        $this->assertTargetVisible($type, $targetId, $user);

        $query = $this->scopedQuery($scope, $user)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name']);

        if ($type === ActivityTargetType::Company) {
            // Only the deals of THIS company that the user can see leak into the
            // aggregate — foreign deals never appear.
            $dealIds = $this->visibleDealIdsForCompany($targetId, $scope, $user);

            $query->where(function (Builder $q) use ($targetId, $dealIds): void {
                $q->where(function (Builder $inner) use ($targetId): void {
                    $inner->where('target_type', ActivityTargetType::Company->value)
                        ->where('target_id', $targetId);
                });

                if ($dealIds !== []) {
                    $q->orWhere(function (Builder $inner) use ($dealIds): void {
                        $inner->where('target_type', ActivityTargetType::Deal->value)
                            ->whereIn('target_id', $dealIds);
                    });
                }
            });
        } else {
            $query->where('target_type', ActivityTargetType::Deal->value)
                ->where('target_id', $targetId);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Create an activity. Applies the stage task_types gate (E1), validates and
     * visibility-gates the target (E5), forces responsible on standalone tasks
     * and stamps department_id (E10).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Activity
    {
        $targetType = $data['target_type'] ?? null;
        $targetId = isset($data['target_id']) ? (int) $data['target_id'] : null;

        if ($targetType !== null) {
            $type = ActivityTargetType::from($targetType);
            $this->assertTargetVisible($type, $targetId, $creator);

            if ($type === ActivityTargetType::Deal) {
                $this->assertKindAllowedOnDeal($targetId, (string) $data['kind']);
            }
        }

        $data['created_by_id'] = $creator->id;

        // status is set here (never via the FormRequest). Mirror the DB default
        // explicitly so the freshly-created in-memory model already carries 'new'
        // — otherwise the POST response serialises status: null and the UI shows
        // the raw i18n key activity.statuses.null until a reload (BUG-3).
        $data['status'] = ActivityStatus::New->value;

        // Standalone (no target) ⇒ personal task: default responsible to self.
        if ($targetType === null) {
            $data['responsible_id'] = $data['responsible_id'] ?? $creator->id;
        }

        $data['department_id'] = $this->resolveDepartmentId($data, $targetType, $targetId, $creator);

        $activity = Activity::create($data);

        ActivityCreated::dispatch($activity);

        if ($activity->responsible_id !== null) {
            ActivityAssigned::dispatch($activity, null);
        }

        return $activity;
    }

    /**
     * Partial update. status and target_* are stripped here (status only via
     * changeStatus()/complete()/reopen(); target is immutable after create).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Activity $activity, array $data): Activity
    {
        unset($data['status'], $data['target_type'], $data['target_id'], $data['created_by_id']);

        $previousResponsible = $activity->responsible_id;

        $activity->update($data);
        $activity->refresh();

        if (array_key_exists('responsible_id', $data)
            && (int) $activity->responsible_id !== (int) $previousResponsible) {
            ActivityAssigned::dispatch($activity, $previousResponsible);
        }

        return $activity;
    }

    /**
     * Mark an activity done (E2). Idempotent — completing an already-done
     * activity is a no-op that returns it unchanged. Notes cannot be completed.
     */
    public function complete(Activity $activity, User $user): Activity
    {
        $this->assertCompletable($activity);

        if ($activity->status === ActivityStatus::Done && $activity->completed_at !== null) {
            return $activity; // idempotent
        }

        $from = $activity->status;

        $activity->update([
            'status' => ActivityStatus::Done,
            'completed_at' => now(),
            'completed_by_id' => $user->id,
            'progress_pct' => 100,
        ]);
        $activity->refresh();

        ActivityStatusChanged::dispatch($activity, $from, ActivityStatus::Done);

        return $activity;
    }

    /**
     * Reopen a completed activity (E2). Idempotent — reopening an already-open
     * activity is a no-op. Notes cannot be reopened.
     */
    public function reopen(Activity $activity, User $user): Activity
    {
        $this->assertCompletable($activity);

        if ($activity->status !== ActivityStatus::Done && $activity->completed_at === null) {
            return $activity; // idempotent — nothing to reopen
        }

        $from = $activity->status;

        $activity->update([
            'status' => ActivityStatus::InProgress,
            'completed_at' => null,
            'completed_by_id' => null,
            'is_closed' => false,
        ]);
        $activity->refresh();

        ActivityStatusChanged::dispatch($activity, $from, ActivityStatus::InProgress);

        return $activity;
    }

    /**
     * Status-machine transition (E3). Illegal transitions are rejected (422).
     * Same-status is a no-op. complete/reopen are the dedicated paths for done.
     *
     * @param  array<string, mixed>  $extra  optional result_text/is_closed
     */
    public function changeStatus(Activity $activity, ActivityStatus $to, User $user, array $extra = []): Activity
    {
        $this->assertCompletable($activity);

        $from = $activity->status;

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Illegal transition: {$from->value} → {$to->value}.",
            ]);
        }

        $payload = ['status' => $to->value];

        if (array_key_exists('result_text', $extra)) {
            $payload['result_text'] = $extra['result_text'];
        }

        if ($to === ActivityStatus::Done) {
            $payload['completed_at'] = now();
            $payload['completed_by_id'] = $user->id;
            $payload['progress_pct'] = 100;
        }

        $activity->update($payload);
        $activity->refresh();

        if ($from !== $to) {
            ActivityStatusChanged::dispatch($activity, $from, $to);
        }

        return $activity;
    }

    public function delete(Activity $activity): void
    {
        $activity->delete();
    }

    /**
     * Activities matching a preset (E4), visibility-scoped. Presets share their
     * predicate with countsByPreset() so a badge can never disagree with its
     * list. Sorted by due_at (nulls last) then created_at desc.
     *
     * Returns a paginator (not a bare Collection) so the resource collection
     * always carries a `meta.total` envelope — every preset tab on the frontend
     * reads res.meta.total and would crash on a meta-less payload (BUG-1).
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function presets(string $preset, VisibilityScope $scope, User $user, int $perPage = 50): LengthAwarePaginator
    {
        $this->assertKnownPreset($preset);

        return $this->scopedQuery($scope, $user)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->where(fn (Builder $q) => $this->applyPreset($q, $preset, $user))
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Counts per preset for sidebar/header badges (E4). Same scoped query and
     * same per-preset predicate as presets() — the single source of truth that
     * fixes the old badge≠list discrepancy.
     *
     * @return array<string, int>
     */
    public function countsByPreset(VisibilityScope $scope, User $user): array
    {
        $counts = [];

        foreach (self::PRESETS as $preset) {
            $counts[$preset] = $this->scopedQuery($scope, $user)
                ->where(fn (Builder $q) => $this->applyPreset($q, $preset, $user))
                ->count();
        }

        return $counts;
    }

    /**
     * Open (not-closed) activities assigned to the user — the header badge.
     */
    public function myOpenCount(User $user): int
    {
        return Activity::query()
            ->where('responsible_id', $user->id)
            ->where('is_closed', false)
            ->count();
    }

    // ---- Private ----

    /**
     * Apply a named preset predicate to a query (E4). Shared by presets() and
     * countsByPreset(). Day/week boundaries use the app timezone consistently.
     *
     * @param  Builder<Activity>  $query
     */
    private function applyPreset(Builder $query, string $preset, User $user): void
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();
        $weekEnd = $todayStart->copy()->addWeek();

        $mineClause = static function (Builder $q) use ($user): void {
            $q->where(function (Builder $inner) use ($user): void {
                $inner->where('responsible_id', $user->id)
                    ->orWhere('created_by_id', $user->id);
            });
        };

        match ($preset) {
            'my_tasks' => $query
                ->where('responsible_id', $user->id)
                ->where('is_closed', false),
            'my_orders' => $query
                ->where('created_by_id', $user->id)
                ->where('is_closed', false),
            'pinned' => $query
                ->where('is_pinned', true)
                ->where('responsible_id', $user->id),
            'overdue' => $query
                ->where('due_at', '<', $now)
                ->where('is_closed', false)
                ->where('status', '!=', ActivityStatus::Done->value)
                ->where($mineClause),
            'today' => $query
                ->where('due_at', '>=', $todayStart)
                ->where('due_at', '<', $todayEnd)
                ->where('is_closed', false)
                ->where($mineClause),
            'this_week' => $query
                ->where('due_at', '>=', $todayStart)
                ->where('due_at', '<', $weekEnd)
                ->where('is_closed', false)
                ->where($mineClause),
            default => null,
        };
    }

    /**
     * task_types gate (E1): on a deal target, kind must be in the stage's
     * task_types whitelist (empty whitelist = all kinds allowed).
     */
    private function assertKindAllowedOnDeal(int $dealId, string $kind): void
    {
        $deal = Deal::query()->with('stage:id,name,task_types')->find($dealId);

        if ($deal === null || $deal->stage === null) {
            return;
        }

        $allowed = $deal->stage->task_types ?? [];

        if ($allowed === [] || in_array($kind, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'kind' => "Activity kind \"{$kind}\" is not allowed at stage \"{$deal->stage->name}\".",
        ]);
    }

    /**
     * Gate the visibility of the polymorphic target (E5). target_id is required
     * when target_type is set; the target must exist AND be visible to the user
     * (via the owning context's policy) — blocking IDOR writes into foreign
     * cards. Cross-domain access goes through policies, never foreign queries.
     */
    private function assertTargetVisible(ActivityTargetType $type, ?int $targetId, User $user): void
    {
        if ($targetId === null) {
            throw ValidationException::withMessages([
                'target_id' => 'target_id is required when target_type is set.',
            ]);
        }

        $model = match ($type) {
            ActivityTargetType::Deal => Deal::find($targetId),
            ActivityTargetType::Company => Company::find($targetId),
        };

        if ($model === null || ! Gate::forUser($user)->allows('view', $model)) {
            throw ValidationException::withMessages([
                'target_id' => 'Target not found or not accessible.',
            ]);
        }
    }

    /**
     * Resolve department_id for denormalisation (E10): explicit > responsible's
     * department > creator's department > target deal's department.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDepartmentId(array $data, ?string $targetType, ?int $targetId, User $creator): ?int
    {
        if (! empty($data['department_id'])) {
            return (int) $data['department_id'];
        }

        $responsibleId = $data['responsible_id'] ?? null;
        if ($responsibleId !== null) {
            $responsible = $responsibleId === $creator->id ? $creator : User::find($responsibleId);
            if ($responsible?->department_id !== null) {
                return (int) $responsible->department_id;
            }
        }

        if ($creator->department_id !== null) {
            return (int) $creator->department_id;
        }

        if ($targetType === ActivityTargetType::Deal->value && $targetId !== null) {
            $deptId = Deal::query()->whereKey($targetId)->value('department_id');
            if ($deptId !== null) {
                return (int) $deptId;
            }
        }

        return null;
    }

    /**
     * Notes have no deadline/completion — complete/reopen/status are not
     * applicable (E2/E3) and return 422.
     */
    private function assertCompletable(Activity $activity): void
    {
        if ($activity->kind === ActivityType::Note) {
            throw ValidationException::withMessages([
                'kind' => 'A note cannot be completed or change status.',
            ]);
        }
    }

    private function assertKnownPreset(string $preset): void
    {
        if (! in_array($preset, self::PRESETS, true)) {
            throw ValidationException::withMessages([
                'preset' => "Unknown preset: {$preset}.",
            ]);
        }
    }

    /**
     * Visible deal ids for a company under scope (E7) — used to aggregate deal
     * activities into a company timeline without leaking foreign deals.
     *
     * @return list<int>
     */
    private function visibleDealIdsForCompany(int $companyId, VisibilityScope $scope, User $user): array
    {
        $query = Deal::query()->where('company_id', $companyId);

        $query = match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn('department_id', $this->visibility->departmentSubtreeIds($user)),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * Apply the row-level visibility scope to a base Activity query (E6).
     * Mirrors DealService::scopedQuery; Department/Own additionally include
     * activities where the user is responsible or the creator.
     *
     * @return Builder<Activity>
     */
    private function scopedQuery(VisibilityScope $scope, User $user): Builder
    {
        $query = Activity::query();

        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->where(function (Builder $q) use ($user): void {
                $q->whereIn('department_id', $this->visibility->departmentSubtreeIds($user))
                    ->orWhere('responsible_id', $user->id)
                    ->orWhere('created_by_id', $user->id);
            }),
            VisibilityScope::Own => $query->where(function (Builder $q) use ($user): void {
                $q->where('responsible_id', $user->id)
                    ->orWhere('created_by_id', $user->id);
            }),
        };
    }
}
