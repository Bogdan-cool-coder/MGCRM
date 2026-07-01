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
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;
use Carbon\Carbon;
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
    private const PRESETS = ['my_tasks', 'my_orders', 'overdue', 'today', 'this_week', 'pinned', 'completed'];

    public function __construct(
        private readonly VisibilityResolver $visibility,
        private readonly EngagementService $engagement,
        private readonly ActivityAuditLogger $auditLogger,
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
        $page = $this->scopedQuery($scope, $user)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name', 'completedBy:id,full_name'])
            ->when(isset($filters['target_type']), fn (Builder $q) => $q->where('target_type', $filters['target_type']))
            ->when(isset($filters['target_id']), fn (Builder $q) => $q->where('target_id', (int) $filters['target_id']))
            ->where(fn (Builder $q) => $this->applyListFilters($q, $filters))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // Stamp the linked deal context (title + stage + company) onto every
        // deal-targeted row in the page in ONE pair of queries, so the task list
        // columns "связанная сделка / компания / статус сделки" render with no
        // N+1 (Задачник 2.0 §список). The Deal lookup is visibility-scoped so a
        // task on a deal that moved out of the user's scope yields null context
        // (E18) — the activity row is still theirs, but the now-foreign deal is not.
        $this->stampDealContext($page->getCollection(), $user, $scope);

        return $page;
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
            // A single non-company target (deal OR contact) returns only that
            // entity's own activities. Previously this hardcoded target_type='deal'
            // for ANY non-company target, so a contact timeline silently queried
            // deal-tasks (B5). Query the actual requested target_type.
            $query->where('target_type', $type->value)
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

        // A scoped actor may only assign to a user inside their visibility (E5b).
        $this->assertResponsibleAssignable(
            isset($data['responsible_id']) ? (int) $data['responsible_id'] : null,
            $creator,
        );

        $data['department_id'] = $this->resolveDepartmentId($data, $targetType, $targetId, $creator);

        $activity = Activity::create($data);

        // Logging an activity is engagement on its target's Crm surface (Контакты
        // 2.0 §B2): a company-targeted activity touches the company; a
        // deal-targeted one touches the deal's company + its linked contacts.
        $this->touchTargetEngagement($activity);

        // The note_added action-journal row (B1) is no longer written inline: the
        // ActivityCreated event below is the single trigger, handled by
        // RecordActivityAuditLogListener (C8). A standalone (target-less) note
        // still writes no row — the logger no-ops on a missing target.
        ActivityCreated::dispatch($activity, $creator);

        if ($activity->responsible_id !== null) {
            ActivityAssigned::dispatch($activity, null);
        }

        return $activity;
    }

    /**
     * Partial update. status and target_* are stripped here (status only via
     * changeStatus()/complete()/reopen(); target is immutable after create).
     *
     * `kind` (task type) is editable inline from the task list — when the activity
     * targets a deal, a kind change is re-gated against the stage task_types
     * whitelist (E1) so an inline edit cannot smuggle in a kind the stage forbids.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Activity $activity, array $data, ?User $actor = null): Activity
    {
        // status, is_closed and the close stamps are derived only by the status
        // machine (changeStatus()/complete()/reopen()); a generic update must
        // never set them or the closed flag desyncs from status. target_* are
        // immutable after create.
        unset(
            $data['status'],
            $data['is_closed'],
            $data['completed_at'],
            $data['completed_by_id'],
            $data['target_type'],
            $data['target_id'],
            $data['created_by_id'],
        );

        // Inline kind change: re-apply the deal stage task_types gate (E1).
        if (array_key_exists('kind', $data)
            && $activity->target_type === ActivityTargetType::Deal->value
            && $activity->target_id !== null) {
            $this->assertKindAllowedOnDeal((int) $activity->target_id, (string) $data['kind']);
        }

        $previousResponsible = $activity->responsible_id;

        // A scoped actor may only reassign to a user inside their visibility
        // (E5b): the receiver must stay bounded by what the actor can see, else a
        // task could be pushed into a foreign department's scope. Skipped when the
        // responsible isn't changing, or when no actor is resolvable.
        if ($actor !== null
            && array_key_exists('responsible_id', $data)
            && (int) ($data['responsible_id'] ?? 0) !== (int) $previousResponsible) {
            $this->assertResponsibleAssignable(
                $data['responsible_id'] !== null ? (int) $data['responsible_id'] : null,
                $actor,
            );
        }

        // Reassignment re-syncs the denormalised department_id from the new
        // responsible (E10): the scope key follows the task's owner so a
        // department-scoped manager keeps seeing a task handed to their report —
        // and stops seeing one handed away. Mirrors DealService::update() owner
        // re-stamp. Skipped when the caller sets department_id explicitly in the
        // same request, and when a clear (null) responsible leaves no owner to
        // derive a department from.
        if (! array_key_exists('department_id', $data)
            && array_key_exists('responsible_id', $data)
            && $data['responsible_id'] !== null
            && (int) $data['responsible_id'] !== (int) $previousResponsible) {
            $newResponsible = User::find($data['responsible_id']);
            if ($newResponsible?->department_id !== null) {
                $data['department_id'] = (int) $newResponsible->department_id;
            }
        }

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
     *
     * An optional $resultText ("Добавить результат" from the task list) is saved
     * onto the activity's result_text in the same write. A null $resultText leaves
     * any existing result untouched (it is only stamped when explicitly provided),
     * so completing without a result never wipes a previously entered one.
     */
    public function complete(Activity $activity, User $user, ?string $resultText = null): Activity
    {
        $this->assertCompletable($activity);

        $from = $activity->status;

        // Race / idempotency guard (B3): two concurrent completes must NOT
        // double-write the completion log or double-bump engagement. A single
        // conditional UPDATE (status != done) is the atomic gate — only the
        // request that flips exactly one row owns the discrete side-effects
        // (engagement + entity-log + ActivityStatusChanged), so they fire AT MOST
        // once even under a double-submit. The losing request falls through to the
        // idempotent no-op below.
        $now = now();

        $payload = [
            'status' => ActivityStatus::Done->value,
            'is_closed' => true,
            'completed_at' => $now,
            'completed_by_id' => $user->id,
            'progress_pct' => 100,
        ];

        if ($resultText !== null) {
            $payload['result_text'] = $resultText;
        }

        $affected = Activity::query()
            ->whereKey($activity->getKey())
            ->where('status', '!=', ActivityStatus::Done->value)
            ->update($payload);

        if ($affected === 0) {
            // Already done (or won the race elsewhere): idempotent no-op, but still
            // allow a late result to be recorded without re-firing side-effects.
            if ($resultText !== null) {
                $activity->update(['result_text' => $resultText]);
            }

            $activity->refresh();

            return $activity;
        }

        $activity->refresh();

        // Exactly one row transitioned here — run the discrete side-effects once.

        // Completing a call/meeting/task is fresh engagement on its target.
        $this->touchTargetEngagement($activity);

        // The completion action-journal rows (the meeting_held/task_completed row
        // on the target + the deal→company/contact fan-out, A1) are no longer
        // written inline: the ActivityStatusChanged event below is the single
        // trigger, handled by RecordActivityAuditLogListener (C8). Because this
        // branch only runs when the conditional UPDATE flipped exactly one row
        // (B3), the event — and therefore the listener's write — fires at most
        // once per real transition.
        ActivityStatusChanged::dispatch($activity, $from, ActivityStatus::Done, $user);

        return $activity;
    }

    /**
     * Reopen a completed activity (E2). Idempotent — reopening an already-open
     * activity is a no-op. Notes cannot be reopened.
     */
    public function reopen(Activity $activity, User $user): Activity
    {
        $this->assertCompletable($activity);

        $from = $activity->status;

        // Single-fire guard mirroring complete() (B3): a conditional UPDATE gated
        // on status = done atomically claims the reopen, so a double-submit can
        // only write ONE task_reopened log row and dispatch ONE status event. The
        // losing request falls through to the idempotent no-op below.
        $affected = Activity::query()
            ->whereKey($activity->getKey())
            ->where('status', ActivityStatus::Done->value)
            ->update([
                'status' => ActivityStatus::InProgress->value,
                'completed_at' => null,
                'completed_by_id' => null,
                'is_closed' => false,
            ]);

        if ($affected === 0) {
            $activity->refresh();

            return $activity; // idempotent — nothing to reopen
        }

        $activity->refresh();

        // The task_reopened action-journal row (B2) is no longer written inline:
        // the ActivityStatusChanged event below (done → in_progress) is the single
        // trigger, handled by RecordActivityAuditLogListener (C8). The conditional
        // UPDATE above (B3) guarantees this fires at most once per reopen.
        ActivityStatusChanged::dispatch($activity, $from, ActivityStatus::InProgress, $user);

        return $activity;
    }

    /**
     * Quick due-date shift from the task list. The caller passes EXACTLY ONE of:
     *  - a {preset} resolved server-side in the operational timezone (start of the
     *    target day, Дубай-окно, no client +4h hack), or
     *  - an explicit {due_at} absolute instant (custom date picker).
     *
     * This is the single, timezone-correct source for the relative shortcuts — the
     * preset means the same thing regardless of the client clock. Reschedule ONLY
     * moves due_at: status, engagement and the entity-log are untouched (it is an
     * update on the task, gated by the update policy in the FormRequest). Notes
     * (no deadline) are rejected via assertCompletable. Returns the activity.
     *
     * The relative presets are anchored on the task's EXISTING due date (its
     * calendar day in the operational timezone), NOT on "today": "+1d" on a task
     * due tomorrow means the day AFTER tomorrow, not a reset to today+1 (BUG: a
     * future task's +1d jumped backwards to start-of-today). A task with no due
     * date falls back to anchoring on today.
     *
     * The existing TIME-OF-DAY is PRESERVED (10.3): shifting a task due 2026-07-01
     * 15:30 by +1d lands on 2026-07-02 15:30, not midnight — only the calendar day
     * changes, the wall-clock deadline stays. A deadline-less task falls back to the
     * start of the target day in the operational timezone (there is no time to keep).
     *
     * Presets (operational TZ, anchored on the existing due day or today when unset;
     * time-of-day preserved from the existing due_at, else start of day):
     *   tomorrow     → anchor day + 1 day
     *   +1d          → alias of tomorrow (anchor day + 1 day)
     *   +1w          → anchor day + 1 week
     *   next_monday  → the next Monday strictly after the anchor day
     *   next_week    → anchor day + 1 week (legacy alias of +1w)
     *   next_month   → anchor day + 1 month (legacy)
     */
    public function reschedule(Activity $activity, ?string $preset = null, ?CarbonInterface $dueAt = null): Activity
    {
        $this->assertCompletable($activity);

        $due = $dueAt !== null
            ? Carbon::instance($dueAt)
            : $this->resolveReschedulePreset((string) $preset, $activity->due_at);

        $activity->update(['due_at' => $due]);
        $activity->refresh();

        return $activity;
    }

    /**
     * Map a quick-reschedule preset to an absolute due_at instant (returned in UTC).
     *
     * The math runs on the existing due date's day in the operational timezone (so
     * the shortcut shifts the REAL deadline forward, never resetting to today), and
     * the existing TIME-OF-DAY is carried onto the new calendar day (10.3): a task
     * due 15:30 that is bumped +1d stays due at 15:30 the next day, not midnight.
     *
     * When the task has no deadline yet there is no time to preserve, so the anchor
     * is today's operational start-of-day and the result lands at 00:00 of the
     * target day (the previous behaviour for a deadline-less task).
     */
    private function resolveReschedulePreset(string $preset, ?CarbonInterface $currentDue): Carbon
    {
        $tz = $this->operationalTimezone();

        // Anchor on the existing due date in the operational timezone — KEEPING its
        // wall-clock time so the deadline's time-of-day survives the shift. A
        // deadline-less task falls back to today's operational start-of-day (00:00),
        // there being no existing time to preserve.
        $anchor = $currentDue !== null
            ? Carbon::instance($currentDue)->setTimezone($tz)
            : $this->operationalLocalDayStart();

        return match ($preset) {
            'tomorrow', '+1d' => $anchor->copy()->addDay()->utc(),
            '+1w', 'next_week' => $anchor->copy()->addWeek()->utc(),
            'next_monday' => $this->nextMondayKeepingTime($anchor)->utc(),
            'next_month' => $anchor->copy()->addMonthNoOverflow()->utc(),
            default => throw ValidationException::withMessages([
                'preset' => "Unknown reschedule preset: {$preset}.",
            ]),
        };
    }

    /**
     * The next Monday strictly after the anchor's calendar day, preserving the
     * anchor's time-of-day. Carbon::next(MONDAY) resets the clock to 00:00, so we
     * re-stamp the original hour/minute/second onto the resulting Monday.
     */
    private function nextMondayKeepingTime(Carbon $anchor): Carbon
    {
        return $anchor->copy()
            ->next(CarbonInterface::MONDAY)
            ->setTime($anchor->hour, $anchor->minute, $anchor->second, $anchor->microsecond);
    }

    /**
     * Status-machine transition (E3). Illegal transitions are rejected (422).
     * Same-status is a no-op. reopen is the dedicated path back out of done.
     *
     * A transition INTO done converges with complete(): it must close the
     * activity (is_closed), stamp engagement on the target and write the
     * completion entity-log — otherwise an inline status→done (MyTasksTable
     * dropdown) would leave the task technically open and invisible to the
     * engagement tiers + feed, diverging from POST /complete. So we route the
     * done branch straight through complete() (idempotent, already done = no-op)
     * and keep this method only for the open-state transitions.
     *
     * @param  array<string, mixed>  $extra  optional result_text
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

        $resultText = array_key_exists('result_text', $extra) ? $extra['result_text'] : null;

        // Done converges with complete() — same close + engagement + entity-log.
        if ($to === ActivityStatus::Done) {
            return $this->complete($activity, $user, $resultText);
        }

        $payload = ['status' => $to->value];

        // Rejected is a terminal work outcome: it must drop the task out of the
        // open-work surfaces (my-board buckets, the my-open badge, the preset
        // lists — all of which key on is_closed = false). Without closing it, a
        // rejected task lingers as "open" and the inline dropdown's optimistic
        // is_closed = true gets reverted by the server response. Re-opening the
        // task (rejected → new/in_progress) clears the flag again.
        if ($to === ActivityStatus::Rejected) {
            $payload['is_closed'] = true;
        } elseif ($from === ActivityStatus::Rejected) {
            $payload['is_closed'] = false;
        }

        if (array_key_exists('result_text', $extra)) {
            $payload['result_text'] = $extra['result_text'];
        }

        // Rejecting is a terminal action logged on the target's canonical timeline
        // (B2). Use the same single-fire guard as complete()/reopen(): a
        // conditional UPDATE gated on status != rejected atomically claims the
        // transition, so a double-submit writes ONE task_rejected log row and
        // dispatches ONE status event. Other open-state transitions keep the plain
        // update (no discrete log event is recorded for them).
        if ($to === ActivityStatus::Rejected) {
            $affected = Activity::query()
                ->whereKey($activity->getKey())
                ->where('status', '!=', ActivityStatus::Rejected->value)
                ->update($payload);

            $activity->refresh();

            if ($affected === 0) {
                return $activity; // already rejected — idempotent no-op
            }

            // The task_rejected action-journal row (B2) is no longer written
            // inline: the ActivityStatusChanged event is the single trigger,
            // handled by RecordActivityAuditLogListener (C8). The conditional
            // UPDATE above (B3) guarantees one event per real reject.
            ActivityStatusChanged::dispatch($activity, $from, $to, $user);

            return $activity;
        }

        $activity->update($payload);
        $activity->refresh();

        if ($from !== $to) {
            ActivityStatusChanged::dispatch($activity, $from, $to, $user);
        }

        return $activity;
    }

    /**
     * Apply the "freshly created and already-completed" side-effects of a
     * meeting/task that was logged in a done state outside the complete() path
     * (e.g. the meeting-report constructor, which writes a done meeting Activity
     * directly). Stamps engagement on the target (last_activity_at) and records
     * the completion entity-log (meeting_held / task_completed) so a report
     * logged through the constructor is visible to the engagement tiers and the
     * activity feed, exactly like a meeting completed via POST /complete (E8).
     *
     * The Activity domain owns both side-effects, so the constructor service
     * delegates here instead of duplicating the EngagementService /
     * ActivityAuditLogger plumbing.
     *
     * This is the ONE completion path that does NOT flow through complete() and so
     * fires NO ActivityStatusChanged event — the meeting-report constructor writes
     * a done meeting directly. It therefore still writes the completion log here,
     * through the SAME ActivityAuditLogger the event listener uses (C8), so the row
     * and fan-out are byte-identical to a meeting completed via POST /complete.
     */
    public function recordCompletedActivitySideEffects(Activity $activity, User $actor): void
    {
        $this->touchTargetEngagement($activity);
        $this->auditLogger->recordCompletion($activity, $actor);
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
     * @param  array<string, mixed>  $filters  optional list filters (kind/status/priority/due_from/due_to/q/responsible_id), narrowed within the preset
     * @return LengthAwarePaginator<int, Activity>
     */
    public function presets(string $preset, VisibilityScope $scope, User $user, int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $this->assertKnownPreset($preset);

        // The MyTasks FilterPanel sends the SAME filter params (kind/status/priority/
        // due_from/due_to/q/responsible_id) to the preset endpoint as to the flat
        // list (useMyTasks.buildParams feeds both getActivities and
        // getPresetActivities). They must NARROW within the preset — a kind/status
        // filter on the "overdue" tab is silently dropped otherwise (D2). Applied on
        // top of the preset predicate, so the preset window always still holds.
        $query = $this->scopedQuery($scope, $user)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->where(fn (Builder $q) => $this->applyPreset($q, $preset, $user))
            ->where(fn (Builder $q) => $this->applyListFilters($q, $filters));

        if ($preset === 'completed') {
            // The «Выполненные» tab is sorted by recency of completion: most
            // recently completed first, nulls last (a closed-but-not-Done row, e.g.
            // rejected, has no completed_at). Other presets keep deadline order
            // (due_at nulls last → due_at → created_at desc).
            $query->orderByRaw('completed_at is null')
                ->orderByDesc('completed_at')
                ->orderByDesc('created_at');
        } else {
            $query->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->orderByDesc('created_at');
        }

        $page = $query->paginate($perPage);

        $this->stampDealContext($page->getCollection(), $user, $scope);

        return $page;
    }

    /**
     * Counts per preset for sidebar/header badges (E4). Same scoped query and
     * same per-preset predicate as presets() — the single source of truth that
     * fixes the old badge≠list discrepancy.
     *
     * One DB round-trip (F23): every preset count is a conditional
     * SUM(CASE WHEN <preset predicate> THEN 1 ELSE 0 END) over the SAME scoped
     * base, instead of one ->count() per preset (6 round-trips) that also re-ran
     * the Department-subtree BFS once per preset. The per-preset predicate is still
     * single-sourced through applyPreset() — extracted as a raw WHERE fragment per
     * preset — so the badge can never drift from the matching list, and the numbers
     * are byte-identical to the old per-preset counts (incl. 'completed').
     *
     * @return array<string, int>
     */
    public function countsByPreset(VisibilityScope $scope, User $user): array
    {
        $query = $this->scopedQuery($scope, $user);

        $selects = [];
        $bindings = [];

        foreach (self::PRESETS as $preset) {
            // Reuse the exact preset predicate: apply it to a throwaway builder,
            // then lift its WHERE SQL + bindings into a CASE column. Wrapping the
            // fragment in parentheses keeps each preset's OR-clauses isolated.
            $predicate = Activity::query()->where(fn (Builder $q) => $this->applyPreset($q, $preset, $user));

            $where = $this->extractWhereSql($predicate);

            if ($where === null) {
                // Defensive: a preset that adds no predicate counts every scoped row.
                $selects[] = "count(*) as {$preset}";

                continue;
            }

            $selects[] = "sum(case when {$where} then 1 else 0 end) as {$preset}";
            $bindings = array_merge($bindings, $predicate->getBindings());
        }

        /** @var object|null $row */
        $row = $query
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $counts = [];

        foreach (self::PRESETS as $preset) {
            $counts[$preset] = (int) ($row?->{$preset} ?? 0);
        }

        return $counts;
    }

    /**
     * Lift the WHERE clause of a built query into a raw SQL fragment (without the
     * leading "where" keyword), so it can be embedded in a CASE expression for the
     * single-query countsByPreset() roll-up. Returns null when the builder carries
     * no WHERE constraint. Bindings are taken from the source builder by the caller.
     */
    private function extractWhereSql(Builder $query): ?string
    {
        $sql = $query->toSql();

        $pos = stripos($sql, ' where ');

        if ($pos === false) {
            return null;
        }

        return substr($sql, $pos + strlen(' where '));
    }

    /**
     * Open activities assigned to the user — the header badge.
     *
     * Routed through the SAME scopedQuery() the lists use (A2/A5) so the badge can
     * never exceed the visible "my tasks" list: count == list. "Open" uses the
     * single-source scopeOpen() predicate (not closed AND status not final), so a
     * rejected task never inflates the badge even if its is_closed flag desynced
     * (D11/D13).
     */
    public function myOpenCount(VisibilityScope $scope, User $user): int
    {
        return $this->scopedQuery($scope, $user)
            ->where('responsible_id', $user->id)
            ->where(fn (Builder $q) => $this->scopeOpen($q))
            ->count();
    }

    /**
     * Count of OPEN task-like activities targeting a specific contact directly —
     * the KPI card signal for ContactResource.
     *
     * "Task-like" mirrors taskLikeValues() (call/meeting/task/follow_up/presentation).
     * "Open" uses the single-source scopeOpen() predicate (not closed AND status not
     * final) so a rejected task is never counted as open (D11/D13).
     *
     * Routed through the SAME scopedQuery() the lists use (A2/A5) so the badge can
     * never exceed the visibility-scoped list — count == list. The scope is
     * resolved from the user's role here (like countDealsWithoutTasks), so the
     * caller stays thin and never re-derives scope.
     *
     * NB: this stays a contact-DIRECT metric (only activities whose target IS this
     * contact), intentionally different from the contact's last_touch_at, which
     * ALSO includes the deal-engagement fan-out (deal-targeted activities touch the
     * deal's contacts). The two answer different questions and must not be merged.
     *
     * Single DB query — safe to call from ContactController::show() without N+1.
     */
    public function openTasksCountForContact(int $contactId, User $user): int
    {
        return $this->scopedQuery($this->visibility->resolve($user), $user)
            ->where('target_type', ActivityTargetType::Contact->value)
            ->where('target_id', $contactId)
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where(fn (Builder $q) => $this->scopeOpen($q))
            ->count();
    }

    /**
     * Aggregated count of OPEN task-like activities across a SET of deals — the
     * "+N по сделкам" signal on the contact KPI (open tasks on every deal the
     * contact is linked to, summed). Mirrors openTasksCountForContact() but keyed
     * on target_type = deal AND target_id IN $dealIds, so the whole fan-out is a
     * SINGLE query (no per-deal N+1).
     *
     * "Task-like" mirrors taskLikeValues(); "open" uses the single-source
     * scopeOpen() predicate (not closed AND status not final) so a rejected/done
     * task is never counted. Routed through the SAME scopedQuery() the lists use
     * so the badge can never exceed the visibility-scoped list (a deal the user
     * cannot see contributes nothing). The scope is resolved from the user's role
     * here, so the caller stays thin.
     *
     * Empty $dealIds short-circuits to 0 (no query) — the contact has no linked
     * deals.
     *
     * @param  list<int>  $dealIds
     */
    public function openTasksCountForDeals(array $dealIds, User $user): int
    {
        if ($dealIds === []) {
            return 0;
        }

        return $this->scopedQuery($this->visibility->resolve($user), $user)
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('target_id', $dealIds)
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where(fn (Builder $q) => $this->scopeOpen($q))
            ->count();
    }

    /**
     * Number of OPEN deals (stage not won/lost) in a pipeline, within the user's
     * visibility scope, that have NO open next task (S1.7 dashboard "deals without
     * tasks" widget, BQ1).
     *
     * "Without tasks" is single-sourced on the Deal::nextTask relation predicate
     * (#10): exactly whereDoesntHave('nextTask'), the same criterion the deep-linked
     * list uses for only_no_task (DealService::applyFilters → whereDoesntHave
     * ('nextTask')) and the KPI no_task chip (DealKpiService::noTask). nextTask is
     * the open task-like activity (call/meeting/task, is_closed=false, status!=Done,
     * due_at IS NOT NULL) with the soonest due_at. Reusing the relation guarantees
     * the badge count equals the list it links to — they can no longer drift over a
     * status=Done-but-not-closed or a due-less activity, which the old hand-rolled
     * NOT EXISTS counted differently.
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

        $query = Deal::query()
            ->where('pipeline_id', $pipelineId)
            // Open deals only: exclude won/lost stages (status lives on the stage).
            ->whereHas('stage', function (Builder $q): void {
                $q->where('is_won', false)->where('is_lost', false);
            })
            // Single-sourced with the list / KPI: no open next task on this deal.
            ->whereDoesntHave('nextTask');

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
            ->where(fn (Builder $q) => $this->scopeOpen($q))
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
     * Batched "last contact" date per deal — the date of the most recent COMPLETED
     * client-facing event (call / follow-up / meeting / presentation) on each deal,
     * powering the deals-list «Посл. контакт» column + its freshness colour
     * (SalesFunnel-spec §5.2/§5.3). The Sales domain never queries activities
     * directly (DDD §2); it asks the Activity domain through this method, mirroring
     * nextTasksForDeals() so the list enrichment stays one batched query (no N+1).
     *
     * "Completed" = status = done with a completed_at timestamp; only event-class
     * kinds count (a note/task is documentation, not a contact). Returns a map of
     * deal id → ISO-8601 date; deals with no completed contact are simply absent
     * (the resource renders null).
     *
     * @param  list<int>  $dealIds
     * @return array<int, string>
     */
    public function lastContactDatesForDeals(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $base = Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('target_id', $dealIds)
            ->whereIn('kind', ActivityType::eventValues())
            ->where('status', ActivityStatus::Done->value)
            ->whereNotNull('completed_at');

        if (DB::connection()->getDriverName() === 'pgsql') {
            // One row per deal: the latest-completed contact via ROW_NUMBER().
            $ranked = (clone $base)
                ->selectRaw('target_id, completed_at, '.
                    'ROW_NUMBER() OVER (PARTITION BY target_id ORDER BY completed_at DESC, id DESC) AS rn');

            $rows = Activity::query()
                ->fromSub($ranked, 'ranked')
                ->where('rn', 1)
                ->get(['target_id', 'completed_at']);
        } else {
            // SQLite fallback: keep the newest-completed contact per deal in PHP.
            // The set is bounded by the list page size, so this stays cheap.
            $rows = (clone $base)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get(['id', 'target_id', 'completed_at'])
                ->unique('target_id')
                ->values();
        }

        $map = [];

        foreach ($rows as $row) {
            $completedAt = $row->completed_at; // Carbon (datetime cast)

            if ($completedAt === null) {
                continue;
            }

            $map[(int) $row->target_id] = $completedAt->toIso8601String();
        }

        return $map;
    }

    /**
     * Key-action timeline dates for a single deal's card header (DealPage 2.0 —
     * «ключевые действия»). The Sales domain never queries the activities table
     * directly (DDD §2); it asks the Activity domain through this method.
     *
     * Returns the dates (ISO-8601, or null) of:
     *   - last_presentation_at — the last COMPLETED presentation activity;
     *   - last_touch_at        — the last COMPLETED touch (call / follow-up);
     *   - last_event_at        — the last COMPLETED event (call / follow-up /
     *                            meeting / presentation).
     *
     * "Completed" = status = done with a completed_at timestamp; the latest is the
     * max completed_at. The three kind-sets are single-sourced on ActivityType so
     * the header can never drift from the timeline. Resolved in one query (per
     * deal) ordered by completed_at desc.
     *
     * @return array{last_presentation_at: ?string, last_touch_at: ?string, last_event_at: ?string}
     */
    public function keyActionDatesForDeal(int $dealId): array
    {
        $eventKinds = ActivityType::eventValues();
        $touchKinds = ActivityType::touchValues();

        // One query: the completed event-class activities on this deal, newest
        // first. The set is small (a deal's lifetime touches), so deriving the
        // three "last" dates in PHP avoids three separate aggregate queries.
        $rows = Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $dealId)
            ->where('status', ActivityStatus::Done->value)
            ->whereNotNull('completed_at')
            ->whereIn('kind', $eventKinds)
            ->orderByDesc('completed_at')
            ->get(['kind', 'completed_at']);

        $lastPresentation = null;
        $lastTouch = null;
        $lastEvent = null;

        foreach ($rows as $row) {
            $completedAt = $row->completed_at; // Carbon (datetime cast)
            $kind = $row->kind instanceof ActivityType ? $row->kind->value : (string) $row->kind;

            // Rows are completed_at desc → the first match per bucket is the latest.
            $lastEvent ??= $completedAt;

            if ($lastTouch === null && in_array($kind, $touchKinds, true)) {
                $lastTouch = $completedAt;
            }

            if ($lastPresentation === null && $kind === ActivityType::Presentation->value) {
                $lastPresentation = $completedAt;
            }
        }

        return [
            'last_presentation_at' => $lastPresentation?->toIso8601String(),
            'last_touch_at' => $lastTouch?->toIso8601String(),
            'last_event_at' => $lastEvent?->toIso8601String(),
        ];
    }

    /**
     * Activity stats for a single deal's «Активность» tab (DealPage metrics block):
     * the total number of activities linked to the deal and the timestamp of the
     * most recent one (by created_at). The Sales domain never queries the
     * activities table directly (DDD §2); it asks the Activity domain here.
     *
     * "Activities count" counts every activity targeted at the deal regardless of
     * kind or status (calls, meetings, tasks, notes — the whole timeline). The
     * last-activity timestamp is the newest activity's created_at, or null when the
     * deal has none. Resolved in ONE aggregate query (no per-row hydration).
     *
     * @return array{activities_count: int, last_activity_at: ?string}
     */
    public function dealActivityStats(int $dealId): array
    {
        $row = Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $dealId)
            ->selectRaw('COUNT(*) as activities_count, MAX(created_at) as last_activity_at')
            ->first();

        $count = (int) ($row?->activities_count ?? 0);
        $lastRaw = $row?->last_activity_at;

        $lastActivityAt = $lastRaw !== null
            ? Carbon::parse((string) $lastRaw)->toIso8601String()
            : null;

        return [
            'activities_count' => $count,
            'last_activity_at' => $lastActivityAt,
        ];
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
     *   later      — due on/after next-week-end (further-out horizon, #6)
     *
     * Tasks with no due_at fall into this_week (ТЗ §4.2 default). Tasks due beyond
     * next week land in "later" (previously dropped — #6). Every bucket is an
     * ordered list (soonest first); buckets are always present (possibly empty) so
     * the frontend can render fixed columns without null checks.
     *
     * @return array<string, list<Activity>>
     */
    public function myBoard(User $user, ?string $search = null): array
    {
        // Day/week boundaries are anchored to the OPERATIONAL timezone (Дубай-окно)
        // so a task due early in the Dubai morning lands in "today", not the
        // previous UTC day (MINOR-8). due_at is a real UTC instant, so every
        // boundary is normalised to UTC for accurate absolute-instant comparison.
        // The calendar-week edges are computed on the Dubai-zoned day start first
        // (so Mon–Sun aligns to the Dubai calendar) and only then converted to UTC.
        $todayStart = $this->operationalTodayStart();
        $tomorrowStart = $todayStart->copy()->addDay();
        $dayAfterTomorrow = $tomorrowStart->copy()->addDay();

        $localDayStart = $this->operationalLocalDayStart();
        $thisWeekEnd = $localDayStart->copy()->endOfWeek()->addSecond()->utc(); // exclusive next-week start
        $nextWeekEnd = $thisWeekEnd->copy()->addWeek();

        $activities = Activity::query()
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where(fn (Builder $q) => $this->scopeOpen($q))
            ->where(function (Builder $q) use ($user): void {
                $q->where('responsible_id', $user->id)
                    ->orWhere('created_by_id', $user->id);
            })
            ->when(
                $search !== null && $search !== '',
                // Case-insensitive search (whereLikeCi → ILIKE on PG / LOWER() on
                // SQLite): a Cyrillic «Звонок» must match the stored «звонок» on the
                // board exactly as it does in the flat task list. The macro wraps the
                // term in %...% itself, so no manual wildcards here.
                fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                    $inner->whereLikeCi('title', $search)
                        ->orWhereLikeCi('body', $search);
                }),
            )
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->get();

        // Batch-resolve the linked deal context (title + stage + company) for the
        // whole board in one pair of queries, stamped onto each activity so
        // ActivityCardResource renders the parent deal / its company / its stage
        // with no N+1 (Сделки — ТЗ §4.3 + Задачник 2.0 §карточка). The Deal lookup
        // is visibility-scoped (resolved from the user's role) so a board task on a
        // now-foreign deal renders null context instead of leaking it (E18).
        $this->stampDealContext($activities, $user);

        $buckets = [
            'overdue' => [],
            'today' => [],
            'tomorrow' => [],
            'this_week' => [],
            'next_week' => [],
            'later' => [],
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
            } else {
                // On/after next-week-end → "later" horizon (#6). Previously these
                // were silently dropped, hiding any task due more than two weeks out.
                $buckets['later'][] = $activity;
            }
        }

        return $buckets;
    }

    // ---- Private ----

    /**
     * Stamp last_activity_at on the Crm entities behind an activity's target
     * (Контакты 2.0 §B2 engagement signal). A company target touches the company
     * directly; a contact target touches that contact directly; a deal target fans
     * out to the deal's company + linked contacts. Standalone (target-less)
     * personal tasks touch nothing. The deal → {company, contacts} resolution lives
     * once in Deal::engagementTargets(), so neither the Sales nor the Activity
     * domain duplicates the deal_contacts lookup. Crossing into the Crm domain goes
     * through EngagementService (a public service method), never a foreign-table
     * query.
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

        if ($targetType === ActivityTargetType::Contact->value) {
            // A directly contact-targeted activity stamps that contact only — it is
            // NOT routed through the deal_contacts fan-out (that path stays reserved
            // for deal-targeted activities).
            $this->engagement->touch('contact', $targetId);

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
     * Stamp the linked deal context onto every deal-targeted activity in a set,
     * batched into one Deal query (eager-loading its stage + company) so both the
     * task list and the personal task board render the columns "связанная сделка /
     * компания / статус сделки" with no N+1. Mutates the collection in place.
     *
     * Each deal-targeted activity gets a `deal_context` attribute:
     *   { id, title, stage: {id,name,color,is_won,is_lost}|null,
     *     company: {id,name}|null }
     * Non-deal (company/contact/standalone) activities get a null deal_context.
     * The Activity domain reads the Deal model only through its own batched lookup
     * (no foreign-table coupling beyond the already-imported Sales models).
     *
     * The Deal lookup is visibility-scoped (E18): a user may legitimately OWN an
     * activity on a deal that later moved out of their scope, but they must not see
     * the now-foreign deal's title/stage/company through the task context. Filtering
     * the Deal query through the SAME scope as the deal board (owner_user_id +
     * department subtree, via VisibilityResolver::applyScope) makes a now-foreign
     * deal resolve to null context — without dropping the user's own activity row.
     * The scope is resolved from the user's role when not supplied by the caller.
     *
     * @param  Collection<int, Activity>  $activities
     */
    private function stampDealContext(Collection $activities, User $user, ?VisibilityScope $scope = null): void
    {
        $dealIds = $activities
            ->filter(static fn (Activity $a): bool => $a->target_type === ActivityTargetType::Deal->value && $a->target_id !== null)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        /** @var Collection<int, Deal> $deals */
        $deals = $dealIds === []
            ? collect()
            : $this->visibility->applyScope(
                Deal::query()
                    ->whereIn('id', $dealIds)
                    ->with(['stage:id,name,color,is_won,is_lost', 'company:id,name']),
                $user,
                ['owner_user_id'],
                'department_id',
                $scope,
            )
                ->get()
                ->keyBy('id');

        foreach ($activities as $activity) {
            $context = null;

            if ($activity->target_type === ActivityTargetType::Deal->value && $activity->target_id !== null) {
                $deal = $deals->get((int) $activity->target_id);

                if ($deal !== null) {
                    $context = [
                        'id' => (int) $deal->id,
                        'title' => $deal->title,
                        'stage' => $deal->stage === null ? null : [
                            'id' => (int) $deal->stage->id,
                            'name' => $deal->stage->name,
                            'color' => $deal->stage->color,
                            'is_won' => (bool) $deal->stage->is_won,
                            'is_lost' => (bool) $deal->stage->is_lost,
                        ],
                        'company' => $deal->company === null ? null : [
                            'id' => (int) $deal->company->id,
                            'name' => $deal->company->name,
                        ],
                    ];
                }
            }

            $activity->setAttribute('deal_context', $context);
        }

        // The task card also links directly-targeted contacts/companies (not just
        // deals) — stamp their mini-context in the same enrichment pass (10.4).
        $this->stampTargetContext($activities, $user, $scope);
    }

    /**
     * Stamp a lightweight, batched target context onto every CONTACT- or
     * COMPANY-targeted activity in a set, so the task card can render a link to the
     * parent entity for those targets too — not just deals (10.4). Mutates the
     * collection in place; deal/standalone targets get a null target_context (a
     * deal-targeted card already carries the richer deal_context stamped above).
     *
     * Each contact/company-targeted activity gets a `target_context` attribute:
     *   { type: 'contact'|'company', id, label }
     * where label is the contact's full_name / the company's name.
     *
     * Resolved in at most TWO queries (one whereIn per target kind) regardless of
     * how many activities point at the same entity — no per-row lookup, preserving
     * the board/list no-N+1 guarantee. Both lookups are visibility-scoped (mirroring
     * the deal_context E18 rationale): a user may OWN an activity on a contact/company
     * that later moved out of their scope, but they must not read the now-foreign
     * entity's name through the card. Filtering each lookup through the SAME scope
     * as the owning Crm surface makes a now-foreign target resolve to null context —
     * without dropping the user's own activity row. Contact scopes on owner_id;
     * Company on owner_user_id/responsible_user_id + its department subtree.
     *
     * @param  Collection<int, Activity>  $activities
     */
    private function stampTargetContext(Collection $activities, User $user, ?VisibilityScope $scope = null): void
    {
        $contactIds = $this->targetIdsOfType($activities, ActivityTargetType::Contact);
        $companyIds = $this->targetIdsOfType($activities, ActivityTargetType::Company);

        /** @var Collection<int, Contact> $contacts */
        $contacts = $contactIds === []
            ? collect()
            : $this->visibility->applyScope(
                Contact::query()->whereIn('id', $contactIds),
                $user,
                ['owner_id'],
                null,
                $scope,
            )->get(['id', 'full_name'])->keyBy('id');

        /** @var Collection<int, Company> $companies */
        $companies = $companyIds === []
            ? collect()
            : $this->visibility->applyScope(
                Company::query()->whereIn('id', $companyIds),
                $user,
                ['owner_user_id', 'responsible_user_id'],
                'department_id',
                $scope,
            )->get(['id', 'name'])->keyBy('id');

        foreach ($activities as $activity) {
            $context = null;
            $targetId = $activity->target_id !== null ? (int) $activity->target_id : null;

            if ($targetId !== null && $activity->target_type === ActivityTargetType::Contact->value) {
                $contact = $contacts->get($targetId);

                if ($contact !== null) {
                    $context = [
                        'type' => ActivityTargetType::Contact->value,
                        'id' => (int) $contact->id,
                        'label' => $contact->full_name,
                    ];
                }
            } elseif ($targetId !== null && $activity->target_type === ActivityTargetType::Company->value) {
                $company = $companies->get($targetId);

                if ($company !== null) {
                    $context = [
                        'type' => ActivityTargetType::Company->value,
                        'id' => (int) $company->id,
                        'label' => $company->name,
                    ];
                }
            }

            $activity->setAttribute('target_context', $context);
        }
    }

    /**
     * The distinct integer target ids in a collection for a given target type.
     *
     * @param  Collection<int, Activity>  $activities
     * @return list<int>
     */
    private function targetIdsOfType(Collection $activities, ActivityTargetType $type): array
    {
        return $activities
            ->filter(static fn (Activity $a): bool => $a->target_type === $type->value && $a->target_id !== null)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The five FTM (first-time meeting) conditions (plan §Б2). Delegates to the
     * single source Activity::scopeFtmCounted() so the KPI count, the feed's
     * ftm_only filter, the per-item ftm_counted flag and ManagerKpiService all
     * share one predicate and can never drift apart.
     *
     * @param  Builder<Activity>  $query
     */
    private function applyFtmConditions(Builder $query): void
    {
        $query->ftmCounted();
    }

    /**
     * Apply the MyTasks FilterPanel query params to a list/preset query (D2). The
     * single source for the flat list AND the preset tabs (the FE feeds the same
     * buildParams() to both endpoints), so a filter narrows identically wherever it
     * is applied. Visibility stays intact: this only ADDS predicates inside the
     * already-scoped query, never widens it.
     *
     * Accepted params:
     *   - kind          (string|string[]) — whereIn on kind
     *   - status        (string|string[]) — whereIn on status
     *   - priority      (string|string[]) — whereIn on priority
     *   - responsible_id (int)            — exact responsible (the FE-collected
     *                                       param the backend previously ignored)
     *   - due_from / due_to (date)        — due_at range
     *   - q             (string)          — substring match on title OR body
     *
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyListFilters(Builder $query, array $filters): void
    {
        $query
            ->when(! empty($filters['kind']), fn (Builder $q) => $q->whereIn('kind', (array) $filters['kind']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->whereIn('status', (array) $filters['status']))
            ->when(! empty($filters['priority']), fn (Builder $q) => $q->whereIn('priority', (array) $filters['priority']))
            ->when(! empty($filters['responsible_id']), fn (Builder $q) => $q->where('responsible_id', (int) $filters['responsible_id']))
            ->when(isset($filters['due_from']), fn (Builder $q) => $q->where('due_at', '>=', $filters['due_from']))
            ->when(isset($filters['due_to']), fn (Builder $q) => $q->where('due_at', '<=', $filters['due_to']))
            ->when(
                isset($filters['q']) && $filters['q'] !== '',
                // Case-insensitive (whereLikeCi): PostgreSQL LIKE is case-sensitive,
                // so a Cyrillic «звонок» typed as «Звонок» never matched the stored
                // value under a plain LIKE. whereLikeCi → ILIKE on PG / LOWER() on
                // SQLite, and wraps the term in %...% itself (no manual wildcards).
                fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                    $inner->whereLikeCi('title', (string) $filters['q'])
                        ->orWhereLikeCi('body', (string) $filters['q']);
                }),
            );
    }

    /**
     * Apply a named preset predicate to a query (E4). Shared by presets() and
     * countsByPreset(). Day/week boundaries use the OPERATIONAL timezone (Дубай-
     * окно) consistently so "today"/"this week" mean the same calendar day for the
     * team regardless of the UTC server clock.
     *
     * @param  Builder<Activity>  $query
     */
    private function applyPreset(Builder $query, string $preset, User $user): void
    {
        $todayStart = $this->operationalTodayStart();
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
            // Overdue is single-sourced with the kanban board's overdue COLUMN
            // (myBoard) so the «N просрочено» chip can never disagree with the
            // column count (10.1). The board's overdue bucket holds the user's OPEN,
            // TASK-LIKE activities whose due_at is BEFORE the start of today in the
            // operational timezone. The chip must count exactly those three
            // predicates — not "due before now" (which mis-counted a task due
            // earlier today as overdue), and not any kind (a note is never a board
            // card). Aligning all three (task-like kinds + scopeOpen + due <
            // operationalTodayStart) makes the chip number equal the column count.
            'overdue' => $query
                ->whereIn('kind', ActivityType::taskLikeValues())
                ->where('due_at', '<', $todayStart)
                ->where(fn (Builder $q) => $this->scopeOpen($q))
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
            // The «Выполненные» tab (B6): the current user's CLOSED tasks — done OR
            // closed (a rejected task is closed but not done). Scoped to "my work"
            // (responsible OR creator), same as the open buckets, and routed
            // through the SAME scopedQuery as every preset/list so count == list and
            // visibility holds. Newest-first ordering comes from presets() (due_at
            // nulls last → due_at → created_at desc); completed tasks generally have
            // a due date so this lands them in deadline order.
            'completed' => $query
                ->where(function (Builder $q): void {
                    $q->where('is_closed', true)
                        ->orWhere('status', ActivityStatus::Done->value);
                })
                ->where($mineClause),
            default => null,
        };
    }

    /**
     * The true instant of "today" 00:00 in the OPERATIONAL timezone, normalised to
     * UTC. The team works in Asia/Dubai (UTC+4): computing the day boundary from
     * the UTC server clock would mis-bucket early-Dubai-morning tasks into the
     * previous day (MINOR-8). The operational timezone is the single source already
     * used by the SalesPulse day-window math (config('salespulse.timezone')), so
     * the two never drift.
     *
     * Returned in UTC so it stays an accurate ABSOLUTE instant for both uses:
     *  - board/preset comparisons against the UTC-stored due_at column compare
     *    real instants (no 4h skew);
     *  - when persisted via the Eloquent datetime cast (reschedule) the cast keeps
     *    the UTC wall-clock, so the saved due_at is exactly Dubai-midnight.
     */
    private function operationalTodayStart(): Carbon
    {
        return $this->operationalLocalDayStart()->utc();
    }

    /**
     * "Today" 00:00 as a Carbon STILL in the operational timezone (Дубай-окно).
     * Used where calendar-aware arithmetic must align to the local week (e.g.
     * endOfWeek for the Mon–Sun board buckets); convert to UTC before comparing
     * against the UTC-stored due_at column. operationalTodayStart() is the
     * UTC-normalised form of this.
     */
    private function operationalLocalDayStart(): Carbon
    {
        return Carbon::now($this->operationalTimezone())->startOfDay();
    }

    /**
     * The operational timezone (Дубай-окно) — the single source for every day/week
     * boundary and the reschedule anchor. Reads config('salespulse.timezone') so it
     * never drifts from the SalesPulse day-window math.
     */
    private function operationalTimezone(): string
    {
        /** @var string $tz */
        $tz = config('salespulse.timezone', 'Asia/Dubai');

        return $tz;
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
            ActivityTargetType::Contact => Contact::find($targetId),
        };

        if ($model === null || ! Gate::forUser($user)->allows('view', $model)) {
            throw ValidationException::withMessages([
                'target_id' => 'Target not found or not accessible.',
            ]);
        }
    }

    /**
     * Guard responsible_id reassignment against the actor's visibility scope (E17).
     *
     * The FormRequest only checks exists:users,id, which would let any actor push a
     * task to ANY user — including one they cannot even see, moving the task out of
     * their own scope at will. The allowed-target set must match the actor's REAL
     * read scope, so we resolve the scope FIRST and branch the allowed receivers on
     * it (previously a department subtree was computed even for an Own-scope actor,
     * who can't see that subtree — widening the allowed set beyond their read scope):
     *   All        — assign to anyone (admin / director / lawyer reassign freely);
     *   Department — assign within the actor's department subtree;
     *   Own        — assign to SELF only (an Own-scope actor sees no one else, so
     *                they cannot hand a task to an arbitrary user).
     * Assigning to self or clearing the responsible is always allowed.
     */
    private function assertResponsibleAssignable(?int $responsibleId, User $actor): void
    {
        if ($responsibleId === null || (int) $responsibleId === (int) $actor->id) {
            return; // clearing, or assigning to self — always allowed.
        }

        $scope = $this->visibility->resolve($actor);

        // All scope reassigns freely.
        if ($scope === VisibilityScope::All) {
            return;
        }

        // Own scope sees no one but themselves — only self-assignment is allowed
        // (already returned above for self). Any other target is out of scope.
        if ($scope === VisibilityScope::Own) {
            throw ValidationException::withMessages([
                'responsible_id' => 'You cannot assign this task to a user outside your scope.',
            ]);
        }

        // Department scope: the receiver must sit inside the actor's subtree.
        $allowedDeptIds = $this->visibility->departmentSubtreeIds($actor);
        $targetDeptId = User::query()->whereKey($responsibleId)->value('department_id');

        if ($targetDeptId === null || ! in_array((int) $targetDeptId, $allowedDeptIds, true)) {
            throw ValidationException::withMessages([
                'responsible_id' => 'You cannot assign this task to a user outside your scope.',
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
     * The single-source "open work" predicate, applied IN PLACE. A task is open
     * when it is not closed AND its status is not final (done OR rejected). Keying
     * on is_closed alone OR on status != done alone each left a hole: a rejected
     * task that lost its is_closed flag (or vice-versa) was reported open/overdue,
     * disagreeing across the surfaces (D11/D13). is_closed stays the primary
     * partition; the status guard makes every open/overdue predicate robust to an
     * is_closed/status disagreement.
     *
     * @param  Builder<Activity>  $query
     */
    private function scopeOpen(Builder $query): void
    {
        $query
            ->where('is_closed', false)
            ->whereNotIn('status', [
                ActivityStatus::Done->value,
                ActivityStatus::Rejected->value,
            ]);
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
