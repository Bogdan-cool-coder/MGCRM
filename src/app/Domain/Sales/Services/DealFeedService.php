<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Services\FieldLabelResolver;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Models\DealStageHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * DealFeedService — single chronological timeline for a deal, merging four
 * append-only sources:
 *   - deal_stage_history → type "stage_change"
 *   - activities targeting the deal → type "activity"
 *   - deal_audits → type "field_change"
 *   - entity_logs (payment_fixed only) → type "payment_fixed"
 *
 * MVP approach (decision 2026-06-15): each source is loaded, normalised into a
 * uniform shape [id, type, occurred_at, actor, payload], merged in PHP, sorted
 * by occurred_at desc and paginated manually via forPage(). A SQL UNION refactor
 * is deferred until a deal exceeds ~1000 events. The composite id (activity_{id}
 * / stage_{id} / audit_{id} / payment_{id}) keeps rows from different tables
 * collision-free for the frontend's :key.
 *
 * entity_logs is pulled NARROWLY — only LogAction::PaymentFixed rows. The other
 * action verbs (created / stage_changed / task_completed / meeting_held / …) are
 * already represented in the feed by their stage_history / activity rows, so
 * reading the whole log would DUPLICATE them. payment_fixed has no stage/activity
 * representation, so it is the one log action that must be surfaced here.
 */
class DealFeedService
{
    public const TYPE_STAGE_CHANGE = 'stage_change';

    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_FIELD_CHANGE = 'field_change';

    public const TYPE_PAYMENT_FIXED = 'payment_fixed';

    /**
     * Hard upper bound on rows pulled from EACH source before the in-memory
     * merge. Keeps memory/latency bounded for hot entities while the MVP merge
     * stands; a full SQL-UNION + DB-pagination refactor is deferred. The newest
     * rows are kept (each source query orders by created_at desc), so the first
     * pages of the timeline are always complete.
     */
    private const MAX_SOURCE_ROWS = 500;

    private readonly FieldLabelResolver $fieldLabels;

    /**
     * FieldLabelResolver is optional so `new DealFeedService` (used in unit tests
     * and legacy call sites) keeps working; the container injects the singleton
     * in production. Defaults to a fresh resolver when omitted.
     */
    public function __construct(?FieldLabelResolver $fieldLabels = null)
    {
        $this->fieldLabels = $fieldLabels ?? new FieldLabelResolver;
    }

    /**
     * @param  array{types?: array<int, string>}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int}}
     */
    public function feed(Deal $deal, array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $types = $this->normaliseTypes($filters['types'] ?? null);

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        // Bounded fetch (perf, 2026-06-26 / F27): the final page is the slice
        // [offset, offset+perPage) of the globally date-desc merge. The k-th
        // globally-newest event always sits within the top-k newest of its OWN
        // source (each source is date-desc internally), so pulling the newest
        // (offset + perPage) rows from each source is provably sufficient to
        // reproduce this page byte-for-byte — anything past that index is sliced
        // off anyway. MAX_SOURCE_ROWS stays the hard ceiling so deep-pagination
        // truncation is IDENTICAL to the previous flat-500 behaviour.
        $offset = ($page - 1) * $perPage;
        $fetchLimit = min($offset + $perPage, self::MAX_SOURCE_ROWS);

        // `total` is computed independently from a capped COUNT per source so it
        // matches the previous "sum of min(source_count, 500)" exactly, even
        // though the data fetch now pulls fewer rows. Without this, total would
        // collapse to the bounded fetch size and break meta.total parity.
        $events = collect();
        $total = 0;

        if ($types === null || in_array(self::TYPE_STAGE_CHANGE, $types, true)) {
            $events = $events->merge($this->stageEvents($deal, $fetchLimit));
            $total += $this->cappedCount($this->stageQuery($deal));
        }

        if ($types === null || in_array(self::TYPE_ACTIVITY, $types, true)) {
            $events = $events->merge($this->activityEvents($deal, $fetchLimit));
            $total += $this->cappedCount($this->activityQuery($deal));
        }

        if ($types === null || in_array(self::TYPE_FIELD_CHANGE, $types, true)) {
            $events = $events->merge($this->fieldChangeEvents($deal, $fetchLimit));
            $total += $this->cappedCount($this->fieldChangeQuery($deal));
        }

        if ($types === null || in_array(self::TYPE_PAYMENT_FIXED, $types, true)) {
            $events = $events->merge($this->paymentFixedEvents($deal, $fetchLimit));
            $total += $this->cappedCount($this->paymentFixedQuery($deal));
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
     * Per-source row count, clamped to MAX_SOURCE_ROWS so `total` mirrors the old
     * "sum of min(source_count, 500)" merged-size exactly.
     */
    private function cappedCount(Builder $query): int
    {
        return min($query->count(), self::MAX_SOURCE_ROWS);
    }

    /**
     * Whitelist the requested type filter; an empty or fully-invalid filter means
     * "no filter" (all types). Returns null when no filtering should apply.
     *
     * @return array<int, string>|null
     */
    private function normaliseTypes(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $allowed = [self::TYPE_STAGE_CHANGE, self::TYPE_ACTIVITY, self::TYPE_FIELD_CHANGE, self::TYPE_PAYMENT_FIXED];
        $types = array_values(array_intersect($allowed, $raw));

        return $types === [] ? null : $types;
    }

    /**
     * Base query for the stage-change source — the single place the WHERE/ORDER
     * is defined, shared by both the bounded fetch and the capped count so they
     * can never drift apart.
     *
     * The creation row (from_stage_id = null — the deal's first landing on a
     * stage) is EXCLUDED here: the deal's creation is already represented in the
     * feed by other sources, and surfacing the null-from history row would render
     * a second, redundant "deal created"/empty-from stage_change next to it (#4).
     * This mirrors how stage analytics already drop the genesis row.
     *
     * @return Builder<DealStageHistory>
     */
    private function stageQuery(Deal $deal): Builder
    {
        return DealStageHistory::query()
            ->where('deal_id', $deal->id)
            ->whereNotNull('from_stage_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stageEvents(Deal $deal, int $limit): Collection
    {
        return $this->stageQuery($deal)
            ->with(['fromStage:id,name', 'toStage:id,name', 'user:id,full_name'])
            ->limit($limit)
            ->get()
            ->map(fn (DealStageHistory $row): array => [
                'id' => "stage_{$row->id}",
                'type' => self::TYPE_STAGE_CHANGE,
                'occurred_at' => $row->created_at?->toIso8601String(),
                'actor' => $this->actor($row->user),
                'payload' => [
                    'from_stage' => $row->fromStage === null ? null : [
                        'id' => $row->fromStage->id,
                        'name' => $row->fromStage->name,
                    ],
                    'to_stage' => $row->toStage === null ? null : [
                        'id' => $row->toStage->id,
                        'name' => $row->toStage->name,
                    ],
                    'from_stage_id' => $row->from_stage_id,
                    'to_stage_id' => $row->to_stage_id,
                ],
            ]);
    }

    /**
     * Base query for the activity source. Activities are polymorphic without FK;
     * the deal is matched on target_type + target_id (DDD §2 — no belongsTo Deal
     * relation). Single source of the WHERE/ORDER for fetch + count.
     *
     * Ordering is by the EFFECTIVE feed timestamp, not the raw created_at: a closed
     * (done/rejected) activity that carries a completed_at takes its completion
     * instant as its timeline position (D3 — see activityEffectiveAtSql / the
     * occurred_at mapping in activityEvents). This keeps the bounded fetch
     * (MAX_SOURCE_ROWS, offset+perPage per source) correct — the per-source order
     * MUST match the global merge key (occurred_at), or a just-completed task could
     * be sliced off the first page even though its completion is the newest event.
     * Falls back to created_at when there is no completion stamp (open tasks,
     * rejected tasks with no completed_at), exactly as the occurred_at mapping does.
     *
     * @return Builder<Activity>
     */
    private function activityQuery(Deal $deal): Builder
    {
        return Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $deal->id)
            ->orderByRaw($this->activityEffectiveAtSql().' desc')
            ->orderByDesc('id');
    }

    /**
     * SQL for an activity's EFFECTIVE feed timestamp (D3): the completed_at when the
     * activity is closed/done AND carries a completion stamp, else the created_at.
     * Mirrors the PHP occurred_at mapping in activityEvents() so the per-source
     * ORDER (used by the bounded fetch) can never drift from the merge sort key.
     * COALESCE(completed_at, created_at) gated on the closed/done condition keeps a
     * rejected-without-stamp row on its created_at, identical to the PHP branch.
     */
    private function activityEffectiveAtSql(): string
    {
        $done = ActivityStatus::Done->value;

        return "case when (is_closed = true or status = '{$done}') and completed_at is not null then completed_at else created_at end";
    }

    /**
     * The effective feed timestamp for one activity row (D3, PHP twin of
     * activityEffectiveAtSql): completed_at when the activity is closed/done AND
     * carries a completion stamp, else created_at. Returned as an ISO-8601 string
     * for the uniform feed shape / the occurred_at merge sort.
     */
    private function activityOccurredAt(Activity $row): ?string
    {
        $status = $row->status instanceof ActivityStatus ? $row->status : ActivityStatus::tryFrom((string) $row->status);
        $isClosedOrDone = (bool) $row->is_closed || $status === ActivityStatus::Done;

        if ($isClosedOrDone && $row->completed_at !== null) {
            return $row->completed_at->toIso8601String();
        }

        return $row->created_at?->toIso8601String();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activityEvents(Deal $deal, int $limit): Collection
    {
        return $this->activityQuery($deal)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->limit($limit)
            ->get()
            ->map(fn (Activity $row): array => [
                'id' => "activity_{$row->id}",
                'type' => self::TYPE_ACTIVITY,
                // D3: a completed task must surface in the feed at COMPLETION time,
                // not at its (often much earlier) creation time — otherwise a
                // just-completed task re-sorts back to its original position,
                // scrolled out of view, and looks like the completion wasn't
                // recorded. The effective timeline instant is completed_at when the
                // activity is closed/done AND carries a completion stamp, else
                // created_at (rejected-without-stamp + open tasks keep created_at).
                // This mirrors activityEffectiveAtSql() so the in-memory occurred_at
                // and the per-source ORDER (bounded fetch) never disagree. A
                // separate task_completed feed event is deliberately NOT emitted —
                // that would duplicate the activity row (audit warning).
                'occurred_at' => $this->activityOccurredAt($row),
                'actor' => $this->actor($row->createdBy ?? $row->responsible),
                'payload' => [
                    'activity_id' => $row->id,
                    'kind' => $row->kind instanceof \BackedEnum ? $row->kind->value : $row->kind,
                    'title' => $row->title,
                    'body' => $row->body,
                    'due_at' => $row->due_at?->toIso8601String(),
                    'completed_at' => $row->completed_at?->toIso8601String(),
                    // Real status (new|in_progress|done|rejected) so the feed renders
                    // the actual outcome — a rejected task is NOT a green "done", and
                    // an in_progress task is NOT a "new". The FE previously
                    // reconstructed status from is_closed alone and mislabelled both
                    // (C9). is_closed stays alongside for the closed/open partition.
                    'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                    'is_closed' => (bool) $row->is_closed,
                    'responsible' => $this->actor($row->responsible),
                ],
            ]);
    }

    /**
     * Base query for the field-change source — single source of the WHERE/ORDER
     * for fetch + count.
     *
     * @return Builder<DealAudit>
     */
    private function fieldChangeQuery(Deal $deal): Builder
    {
        return DealAudit::query()
            ->where('deal_id', $deal->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fieldChangeEvents(Deal $deal, int $limit): Collection
    {
        return $this->fieldChangeQuery($deal)
            ->with('user:id,full_name')
            ->limit($limit)
            ->get()
            ->map(fn (DealAudit $row): array => [
                'id' => "audit_{$row->id}",
                'type' => self::TYPE_FIELD_CHANGE,
                'occurred_at' => $row->created_at?->toIso8601String(),
                'actor' => $this->actor($row->user),
                'payload' => [
                    'field' => $row->field,
                    // Human-readable RU label for the field (e.g. discount_percent
                    // → «Скидка»). `field` is kept for compatibility; the frontend
                    // renders field_label || field.
                    'field_label' => $this->fieldLabels->forDeal($row->field),
                    'old_value' => $row->old_value,
                    'new_value' => $row->new_value,
                ],
            ]);
    }

    /**
     * Base query for the payment-fixed source — entity_logs rows on THIS deal
     * with action = payment_fixed only (see class docblock for why the rest of
     * the log vocabulary is intentionally excluded). Single source of the
     * WHERE/ORDER for fetch + count.
     *
     * @return Builder<EntityLog>
     */
    private function paymentFixedQuery(Deal $deal): Builder
    {
        return EntityLog::query()
            ->where('subject_type', LogSubjectType::Deal->value)
            ->where('subject_id', $deal->id)
            ->where('action', LogAction::PaymentFixed->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentFixedEvents(Deal $deal, int $limit): Collection
    {
        return $this->paymentFixedQuery($deal)
            ->with('actor:id,full_name')
            ->limit($limit)
            ->get()
            ->map(function (EntityLog $row): array {
                $meta = is_array($row->meta) ? $row->meta : [];

                return [
                    'id' => "payment_{$row->id}",
                    'type' => self::TYPE_PAYMENT_FIXED,
                    'occurred_at' => $row->created_at?->toIso8601String(),
                    'actor' => $this->actor($row->actor),
                    'payload' => [
                        'amount' => $meta['amount'] ?? null,
                        'currency' => $meta['currency'] ?? null,
                        'paid_at' => $meta['paid_at'] ?? null,
                    ],
                ];
            });
    }

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
