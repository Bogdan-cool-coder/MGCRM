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
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly EngagementService $engagement,
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

        // Logging an activity is engagement on its target's Crm surface (Контакты
        // 2.0 §B2): a company-targeted activity touches the company; a
        // deal-targeted one touches the deal's company + its linked contacts.
        $this->touchTargetEngagement($activity);

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

        // Completing a call/meeting/task is fresh engagement on its target.
        $this->touchTargetEngagement($activity);

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

    /**
     * Number of OPEN deals (stage not won/lost) in a pipeline, within the user's
     * visibility scope, that have NO open task-like activity (S1.7 dashboard
     * "deals without tasks" widget, BQ1).
     *
     * A deal counts as "without tasks" when there is no open Activity of a
     * task-like kind (call/meeting/task with is_closed = false) targeting it —
     * a NOT EXISTS correlated subquery on activities (target_type = 'deal').
     *
     * This is the public contract consumed by SalesDashboardService: the Sales
     * domain never imports the Activity model directly (DDD §2) and instead asks
     * the Activity domain through this method. Visibility is resolved here from
     * the user's role via VisibilityResolver and applied to the Deal query
     * exactly like DealService::scopedQuery, so the count can never leak deals
     * the user may not see (E6).
     */
    public function countDealsWithoutTasks(int $pipelineId, User $user): int
    {
        $scope = $this->visibility->resolve($user);

        $taskLikeKinds = ActivityType::taskLikeValues();

        $query = Deal::query()
            ->where('pipeline_id', $pipelineId)
            // Open deals only: exclude won/lost stages (status lives on the stage).
            ->whereHas('stage', function (Builder $q): void {
                $q->where('is_won', false)->where('is_lost', false);
            })
            // No open task-like activity targets this deal.
            ->whereNotExists(function ($sub) use ($taskLikeKinds): void {
                $sub->select(DB::raw(1))
                    ->from('activities')
                    ->whereColumn('activities.target_id', 'deals.id')
                    ->where('activities.target_type', ActivityTargetType::Deal->value)
                    ->whereIn('activities.kind', $taskLikeKinds)
                    ->where('activities.is_closed', false);
            });

        $query = match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn('department_id', $this->visibility->departmentSubtreeIds($user)),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };

        return $query->count();
    }

    /**
     * Number of counted FTM (first-time meetings) for a user in a period — the
     * public contract consumed by the S1.8 manager cabinet (ManagerKpiService).
     * The Sales domain never imports the Activity model directly (DDD §2); it
     * asks the Activity domain through this method.
     *
     * An activity is a counted FTM when ALL five FTM conditions are true (plan
     * §Б2): kind = meeting, is_first_time_meeting, ftm_decision_maker_attended,
     * ftm_presentation_shown and ftm_report_url IS NOT NULL — scoped to the
     * user's own meetings (responsible_id) and to created_at within [from, to].
     *
     * The five-condition predicate is single-sourced in applyFtmConditions() so
     * this KPI count can never drift from the ftm_counted flag rendered in the
     * activity feed (risk Н: "FTM-формула расходится с отображением в ленте").
     */
    public function countFtmForUser(int $userId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Activity::query()
            ->where('responsible_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->where(fn (Builder $q) => $this->applyFtmConditions($q))
            ->count();
    }

    /**
     * Paginated activity feed for a single user — the public contract consumed
     * by the S1.8 manager cabinet (GET /api/me/activity-feed via
     * ManagerKpiService). Scoped to the user's own activities (responsible_id),
     * newest first.
     *
     * Supported filters (plan §Б4):
     *  - kind: 'call'|'meeting'|'task'|'note' (or 'all'/null for every kind);
     *  - from / to (Carbon): restrict by created_at to the stepper period;
     *  - ftm_only (bool): keep only counted FTM meetings (the same five-condition
     *    predicate as countFtmForUser, via applyFtmConditions()).
     *
     * The five-condition FTM predicate is single-sourced so the ftm_only filter
     * and the per-item ftm_counted flag stay in lockstep with the KPI count.
     *
     * @param  array<string, mixed>  $filters  kind, from, to, ftm_only
     * @return LengthAwarePaginator<int, Activity>
     */
    public function feedForUser(int $userId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $kind = $filters['kind'] ?? null;

        return Activity::query()
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->where('responsible_id', $userId)
            ->when($kind !== null && $kind !== 'all', fn (Builder $q) => $q->where('kind', $kind))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['to']))
            ->when(
                ! empty($filters['ftm_only']),
                fn (Builder $q) => $q->where(fn (Builder $inner) => $this->applyFtmConditions($inner)),
            )
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Batched "next task" lookup for a set of deals — the public contract that
     * powers the Kanban card health signal (Сделки — ТЗ §5.3). The Sales domain
     * never queries the activities table directly (DDD §2); it asks here.
     *
     * "Next task" = the OPEN, task-like activity (call/meeting/task/follow_up,
     * is_closed = false, status != done, due_at NOT NULL) targeting the deal with
     * the soonest due_at — exactly the Deal::nextTask() relation predicate, kept
     * in lockstep so card and DealPage chip can never drift.
     *
     * Returns one map keyed by deal id; absent ids have no open task. The whole
     * column is resolved in a single query (no N+1) using a window function on
     * PostgreSQL and a correlated-min fallback on SQLite (test :memory:).
     *
     * @param  list<int>  $dealIds
     * @return array<int, array{id: int, type: string, title: string, due_at: ?string, is_overdue: bool}>
     */
    public function nextTasksForDeals(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $now = now();

        $base = Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('target_id', $dealIds)
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where('is_closed', false)
            ->where('status', '!=', ActivityStatus::Done->value)
            ->whereNotNull('due_at');

        if (DB::connection()->getDriverName() === 'pgsql') {
            // One row per deal: the earliest-due open task via ROW_NUMBER().
            $ranked = (clone $base)
                ->selectRaw('id, target_id, kind, title, due_at, '.
                    'ROW_NUMBER() OVER (PARTITION BY target_id ORDER BY due_at ASC, id ASC) AS rn');

            $rows = Activity::query()
                ->fromSub($ranked, 'ranked')
                ->where('rn', 1)
                ->get();
        } else {
            // SQLite fallback: keep the soonest-due task per deal in PHP. The set
            // is bounded by the board column limit, so this stays cheap.
            $rows = (clone $base)
                ->orderBy('due_at')
                ->orderBy('id')
                ->get(['id', 'target_id', 'kind', 'title', 'due_at'])
                ->unique('target_id')
                ->values();
        }

        $map = [];

        foreach ($rows as $row) {
            $dueAt = $row->due_at; // Carbon (datetime cast)

            $map[(int) $row->target_id] = [
                'id' => (int) $row->id,
                'type' => $row->kind instanceof ActivityType ? $row->kind->value : (string) $row->kind,
                'title' => (string) $row->title,
                'due_at' => $dueAt?->toIso8601String(),
                'is_overdue' => $dueAt !== null && $dueAt->lt($now),
            ];
        }

        return $map;
    }

    /**
     * "My tasks" grouped by urgency bucket for the personal task board (Сделки —
     * ТЗ §4). Scoped to the current user's OPEN, task-like activities (those they
     * are responsible for OR created), bucketed by due_at relative to the app
     * timezone "today":
     *
     *   overdue    — due before today's start (and still open)
     *   today      — due within today
     *   tomorrow   — due within tomorrow
     *   this_week  — due after tomorrow, within the current calendar week (→ Sun)
     *   next_week  — due within the following calendar week
     *
     * Tasks with no due_at fall into this_week (ТЗ §4.2 default). Tasks due beyond
     * next week are not bucketed (they belong to a later horizon). Every bucket is
     * an ordered list (soonest first); buckets are always present (possibly empty)
     * so the frontend can render fixed columns without null checks.
     *
     * @return array<string, list<Activity>>
     */
    public function myBoard(User $user, ?string $search = null): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $dayAfterTomorrow = $tomorrowStart->copy()->addDay();
        // Calendar week boundaries (Mon–Sun) in the app timezone.
        $thisWeekEnd = $todayStart->copy()->endOfWeek()->addSecond(); // exclusive next-week start
        $nextWeekEnd = $thisWeekEnd->copy()->addWeek();

        $activities = Activity::query()
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where('is_closed', false)
            ->where('status', '!=', ActivityStatus::Done->value)
            ->where(function (Builder $q) use ($user): void {
                $q->where('responsible_id', $user->id)
                    ->orWhere('created_by_id', $user->id);
            })
            ->when(
                $search !== null && $search !== '',
                fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhere('body', 'like', '%'.$search.'%');
                }),
            )
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->get();

        // Batch-resolve linked deal titles (TaskCard shows the parent deal —
        // Сделки — ТЗ §4.3). One query for the whole board, stamped onto each
        // activity so ActivityCardResource renders it with no N+1.
        $this->stampDealTitles($activities);

        $buckets = [
            'overdue' => [],
            'today' => [],
            'tomorrow' => [],
            'this_week' => [],
            'next_week' => [],
        ];

        foreach ($activities as $activity) {
            $due = $activity->due_at;

            // No deadline → "this week" backlog (ТЗ §4.2).
            if ($due === null) {
                $buckets['this_week'][] = $activity;

                continue;
            }

            if ($due->lt($todayStart)) {
                $buckets['overdue'][] = $activity;
            } elseif ($due->lt($tomorrowStart)) {
                $buckets['today'][] = $activity;
            } elseif ($due->lt($dayAfterTomorrow)) {
                $buckets['tomorrow'][] = $activity;
            } elseif ($due->lt($thisWeekEnd)) {
                $buckets['this_week'][] = $activity;
            } elseif ($due->lt($nextWeekEnd)) {
                $buckets['next_week'][] = $activity;
            }
            // Beyond next week → not bucketed (out of board horizon).
        }

        return $buckets;
    }

    // ---- Private ----

    /**
     * Stamp last_activity_at on the Crm entities behind an activity's target
     * (Контакты 2.0 §B2 engagement signal). A company target touches the company
     * directly; a deal target fans out to the deal's company + linked contacts.
     * Standalone (target-less) personal tasks touch nothing. The deal → {company,
     * contacts} resolution lives once in Deal::engagementTargets(), so neither the
     * Sales nor the Activity domain duplicates the deal_contacts lookup. Crossing
     * into the Crm domain goes through EngagementService (a public service method),
     * never a foreign-table query.
     */
    private function touchTargetEngagement(Activity $activity): void
    {
        $targetType = $activity->target_type;
        $targetId = $activity->target_id !== null ? (int) $activity->target_id : null;

        if ($targetType === null || $targetId === null) {
            return; // standalone personal task — no Crm entity to touch
        }

        if ($targetType === ActivityTargetType::Company->value) {
            $this->engagement->touch('company', $targetId);

            return;
        }

        if ($targetType === ActivityTargetType::Deal->value) {
            $deal = Deal::find($targetId);

            if ($deal !== null) {
                $this->engagement->touchForDeal($deal->engagementTargets());
            }
        }
    }

    /**
     * Stamp the linked deal's title onto each deal-targeted activity (batched) so
     * the personal task board can render the parent-deal line without an N+1.
     * Non-deal activities get a null deal_title. Mutates the collection in place.
     *
     * @param  Collection<int, Activity>  $activities
     */
    private function stampDealTitles(Collection $activities): void
    {
        $dealIds = $activities
            ->filter(static fn (Activity $a): bool => $a->target_type === ActivityTargetType::Deal->value && $a->target_id !== null)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $titles = $dealIds === []
            ? []
            : Deal::query()->whereIn('id', $dealIds)->pluck('title', 'id')->all();

        foreach ($activities as $activity) {
            $title = null;

            if ($activity->target_type === ActivityTargetType::Deal->value && $activity->target_id !== null) {
                $title = $titles[(int) $activity->target_id] ?? null;
            }

            $activity->setAttribute('deal_title', $title);
        }
    }

    /**
     * The five FTM (first-time meeting) conditions (plan §Б2), single-sourced so
     * the countFtmForUser() KPI, the feed's ftm_only filter and the per-item
     * ftm_counted flag share one predicate and can never drift apart.
     *
     * @param  Builder<Activity>  $query
     */
    private function applyFtmConditions(Builder $query): void
    {
        $query->where('kind', ActivityType::Meeting->value)
            ->where('is_first_time_meeting', true)
            ->where('ftm_decision_maker_attended', true)
            ->where('ftm_presentation_shown', true)
            ->whereNotNull('ftm_report_url');
    }

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
