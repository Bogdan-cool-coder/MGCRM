<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CrmFeedService — unified chronological activity timeline for a Company or Contact.
 *
 * Mirrors DealFeedService (Sales domain) but scopes activities by target_type
 * (ActivityTargetType::Company / ActivityTargetType::Contact) and target_id.
 *
 * Two sources are merged: activities and the action log's field-change rows
 * (entity_logs action=data_changed → "field_change") so the "Изменения" feed
 * filter has real content for CRM entities. Shape is identical to the deal feed
 * so the frontend reuses the same normalisation logic in useEntityFeed.ts.
 *
 * A3/A4: the activity source is widened to aggregate linked-deal activities —
 * consistent with how ActivityService::timeline() handles Company targets (E7).
 * Company feed: direct activities + activities of the company's VISIBLE deals.
 * Contact feed: direct activities + activities of the contact's VISIBLE deals
 *   (resolved via the deal_contacts pivot, identical to Deal::engagementTargets()).
 * Visibility gating reuses the same scope/deal-filter the ActivityService uses,
 * so deal activities the authenticated user cannot see are excluded.
 * Dedup (A3/A4): a single activity id seen both as a direct hit and as a
 * deal-linked hit is emitted only once (direct match wins; no double-counting).
 *
 * C9: the activity payload now carries the real `status` enum string
 * (new|in_progress|done|rejected) alongside is_closed, matching DealFeedService.
 *
 * F27 / meta.total parity: each source is split into a base-query method (shared
 * by both the bounded fetch and the capped COUNT) following the DealFeedService
 * pattern exactly. `total` = Σ cappedCount(baseQuery) computed independently of
 * the data fetch so meta.total is byte-identical to the pre-bounded-fetch value.
 *
 * @see DealFeedService
 * @see ActivityService::timeline()
 */
class CrmFeedService
{
    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_FIELD_CHANGE = 'field_change';

    /**
     * Hard upper bound on rows pulled from EACH source before the in-memory
     * merge. Keeps memory/latency bounded for hot entities (newest rows kept).
     * Also the ceiling for cappedCount() so meta.total mirrors the old behaviour.
     */
    private const MAX_SOURCE_ROWS = 500;

    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

    /**
     * @param  array{types?: array<int, string>}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int}}
     */
    public function feed(Company|Contact $entity, User $user, array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $types = $this->normaliseTypes($filters['types'] ?? null);

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        // F27: bounded per-source fetch — pull only the rows that could plausibly
        // appear on pages 1..current given the merge+sort. The k-th globally-newest
        // event always sits within the top-k newest of its OWN source (each source
        // is date-desc internally), so pulling offset+perPage rows per source is
        // provably sufficient to reproduce this page byte-for-byte. MAX_SOURCE_ROWS
        // stays the hard ceiling so deep-pagination truncation is IDENTICAL to the
        // previous flat-500 behaviour.
        $fetchLimit = min(($page - 1) * $perPage + $perPage, self::MAX_SOURCE_ROWS);

        // `total` is computed independently via cappedCount() on each source's base
        // query so it equals the old "Σ min(source_count, 500)" exactly — even
        // though the data fetch now pulls fewer rows. This mirrors DealFeedService.
        $events = collect();
        $total = 0;

        // Resolve deal context once — shared by both the activity fetch and count.
        $scope = $this->visibility->resolve($user);
        $dealIds = $entity instanceof Company
            ? $this->visibleDealIdsForCompany((int) $entity->id, $scope, $user)
            : $this->visibleDealIdsForContact((int) $entity->id, $scope, $user);

        if ($types === null || in_array(self::TYPE_ACTIVITY, $types, true)) {
            $events = $events->merge($this->activityEvents($entity, $dealIds, $fetchLimit));
            $total += $this->cappedCount($this->activityBaseQuery($entity, $dealIds));
        }

        if ($types === null || in_array(self::TYPE_FIELD_CHANGE, $types, true)) {
            $events = $events->merge($this->fieldChangeEvents($entity, $fetchLimit));
            $total += $this->cappedCount($this->fieldChangeBaseQuery($entity));
        }

        $sorted = $events
            ->sortByDesc(fn (array $event): string => $event['occurred_at'] ?? '')
            ->values();

        $data = $sorted->forPage($page, $perPage)->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
            ],
        ];
    }

    /**
     * Per-source row count clamped to MAX_SOURCE_ROWS so `total` mirrors the old
     * "Σ min(source_count, 500)" merged-size exactly. Mirrors DealFeedService::cappedCount().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function cappedCount(Builder $query): int
    {
        return min($query->count(), self::MAX_SOURCE_ROWS);
    }

    /**
     * @return array<int, string>|null
     */
    private function normaliseTypes(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $allowed = [self::TYPE_ACTIVITY, self::TYPE_FIELD_CHANGE];
        $types = array_values(array_intersect($allowed, $raw));

        return $types === [] ? null : $types;
    }

    // ── Activity source ──────────────────────────────────────────────────────

    /**
     * Base query for the activity source — the single place the WHERE/ORDER is
     * defined, shared by both the bounded fetch and the capped COUNT so they can
     * never drift apart. Mirrors DealFeedService::activityQuery().
     *
     * A3/A4: compound OR predicate — direct entity target OR any of the entity's
     * visible deal targets. No limit, no eager-loads (count path needs neither).
     *
     * @param  list<int>  $dealIds
     * @return Builder<Activity>
     */
    private function activityBaseQuery(Company|Contact $entity, array $dealIds): Builder
    {
        $directTargetType = $entity instanceof Company
            ? ActivityTargetType::Company->value
            : ActivityTargetType::Contact->value;

        return Activity::query()
            ->where(function (Builder $q) use ($directTargetType, $entity, $dealIds): void {
                $q->where(function (Builder $inner) use ($directTargetType, $entity): void {
                    $inner->where('target_type', $directTargetType)
                        ->where('target_id', $entity->id);
                });

                if ($dealIds !== []) {
                    $q->orWhere(function (Builder $inner) use ($dealIds): void {
                        $inner->where('target_type', ActivityTargetType::Deal->value)
                            ->whereIn('target_id', $dealIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Load and normalise activity events for a Company or Contact.
     *
     * Uses activityBaseQuery() for the WHERE/ORDER, then adds eager-loads and
     * the bounded fetch limit — exactly as DealFeedService::activityEvents() does.
     *
     * deal_title: after the fetch, a single batched Deal lookup resolves titles
     * for all deal-sourced activities in the result set (no per-row query / N+1).
     *
     * @param  list<int>  $dealIds
     * @return Collection<int, array<string, mixed>>
     */
    private function activityEvents(Company|Contact $entity, array $dealIds, int $fetchLimit): Collection
    {
        $rows = $this->activityBaseQuery($entity, $dealIds)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->limit($fetchLimit)
            ->get()
            ->unique('id'); // dedup: same activity id via both OR paths emitted once

        // Batched deal_title lookup — one query over all deal-sourced rows in the
        // result set, keyed by deal id. Zero extra queries for direct-entity rows.
        $dealActivityIds = $rows
            ->filter(fn (Activity $r): bool => $r->target_type === ActivityTargetType::Deal->value
                && in_array((int) $r->target_id, $dealIds, true))
            ->map(fn (Activity $r): int => (int) $r->target_id)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, string> $dealTitles keyed by deal id */
        $dealTitles = $dealActivityIds !== []
            ? Deal::query()->whereIn('id', $dealActivityIds)->pluck('title', 'id')
            : collect();

        return $rows->map(fn (Activity $row): array => $this->formatActivity($row, $dealIds, $dealTitles));
    }

    /**
     * Shape one Activity row into the uniform feed item.
     *
     * C9: `status` (enum string new|in_progress|done|rejected) is included
     * alongside `is_closed` — matching DealFeedService::activityEvents() exactly.
     *
     * `deal_id` is set when the activity targets a deal (A3/A4 context hint), so
     * the frontend can render "activity on Deal #X" when desired. Null for
     * entity-direct activities.
     *
     * `deal_title` is the deal's title string resolved from the pre-fetched
     * $dealTitles map (single batched lookup — no N+1). Null for direct-entity
     * activities so the FE chip only appears for deal-sourced rows.
     *
     * @param  list<int>  $dealIds
     * @param  Collection<int, string>  $dealTitles  keyed by deal id
     * @return array<string, mixed>
     */
    private function formatActivity(Activity $row, array $dealIds, Collection $dealTitles): array
    {
        $isDealActivity = $row->target_type === ActivityTargetType::Deal->value
            && in_array((int) $row->target_id, $dealIds, true);

        $dealId = $isDealActivity ? (int) $row->target_id : null;

        return [
            'id' => "activity_{$row->id}",
            'type' => self::TYPE_ACTIVITY,
            'occurred_at' => $row->created_at?->toIso8601String(),
            'actor' => $this->actor($row->createdBy ?? $row->responsible),
            'payload' => [
                'activity_id' => $row->id,
                'kind' => $row->kind instanceof \BackedEnum ? $row->kind->value : $row->kind,
                'title' => $row->title,
                'body' => $row->body,
                'due_at' => $row->due_at?->toIso8601String(),
                'completed_at' => $row->completed_at?->toIso8601String(),
                // C9: real status string (new|in_progress|done|rejected).
                // is_closed kept alongside for the closed/open partition (unchanged contract).
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                'is_closed' => (bool) $row->is_closed,
                'target_type' => $row->target_type,
                // A3/A4: deal_id context hint — non-null when the activity comes from
                // a linked deal rather than the entity directly.
                'deal_id' => $dealId,
                // deal_title: resolved from a single batched lookup (no N+1).
                // Null for direct-entity (non-deal) activities.
                'deal_title' => $dealId !== null ? ($dealTitles->get($dealId) ?? null) : null,
                'responsible' => $this->actor($row->responsible),
            ],
        ];
    }

    // ── Field-change source ──────────────────────────────────────────────────

    /**
     * Base query for the field-change source — single place the WHERE/ORDER is
     * defined, shared by both the bounded fetch and the capped COUNT.
     * Mirrors DealFeedService::fieldChangeQuery() pattern.
     *
     * @return Builder<EntityLog>
     */
    private function fieldChangeBaseQuery(Company|Contact $entity): Builder
    {
        $subjectType = $entity instanceof Company
            ? LogSubjectType::Company
            : LogSubjectType::Contact;

        return EntityLog::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $entity->id)
            ->where('action', LogAction::DataChanged->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Field-change track for the "Изменения" feed filter — the action log's
     * data_changed rows for this subject. Uses fieldChangeBaseQuery() for the
     * WHERE/ORDER, adds eager-loads and the bounded fetch limit.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fieldChangeEvents(Company|Contact $entity, int $fetchLimit): Collection
    {
        return $this->fieldChangeBaseQuery($entity)
            ->with('actor:id,full_name')
            ->limit($fetchLimit)
            ->get()
            ->map(fn (EntityLog $row): array => [
                'id' => "log_{$row->id}",
                'type' => self::TYPE_FIELD_CHANGE,
                'occurred_at' => $row->created_at?->toIso8601String(),
                'actor' => $this->actor($row->actor),
                'payload' => [
                    // meta.changes = [{field, old, new}] — normalised on the FE.
                    'changes' => is_array($row->meta['changes'] ?? null) ? $row->meta['changes'] : [],
                ],
            ]);
    }

    // ── Deal-id resolution ───────────────────────────────────────────────────

    /**
     * Visible deal ids for a company under the user's scope (A3).
     * Mirrors ActivityService::visibleDealIdsForCompany() exactly — same predicate,
     * same scope branches — so the two feeds can never diverge on deal resolution.
     *
     * @return list<int>
     */
    private function visibleDealIdsForCompany(int $companyId, VisibilityScope $scope, User $user): array
    {
        $query = Deal::query()->where('company_id', $companyId);

        $query = match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn(
                'department_id',
                $this->visibility->departmentSubtreeIds($user),
            ),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * Visible deal ids for a contact under the user's scope (A4).
     * Resolves the contact's deals via the deal_contacts pivot, then applies
     * the same visibility gate as the company path. Mirrors the fan-out in
     * Deal::engagementTargets() (which uses the same pivot in the other direction).
     *
     * @return list<int>
     */
    public function visibleDealIdsForContact(int $contactId, VisibilityScope $scope, User $user): array
    {
        $allDealIds = DealContact::query()
            ->where('contact_id', $contactId)
            ->pluck('deal_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($allDealIds === []) {
            return [];
        }

        $query = Deal::query()->whereIn('id', $allDealIds);

        $query = match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn(
                'department_id',
                $this->visibility->departmentSubtreeIds($user),
            ),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{id: int, full_name: string|null}|null
     */
    private function actor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
        ];
    }
}
