<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Models\DealStageHistory;
use Illuminate\Support\Collection;

/**
 * DealFeedService — single chronological timeline for a deal, merging three
 * append-only sources:
 *   - deal_stage_history → type "stage_change"
 *   - activities targeting the deal → type "activity"
 *   - deal_audits → type "field_change"
 *
 * MVP approach (decision 2026-06-15): each source is loaded, normalised into a
 * uniform shape [id, type, occurred_at, actor, payload], merged in PHP, sorted
 * by occurred_at desc and paginated manually via forPage(). A SQL UNION refactor
 * is deferred until a deal exceeds ~1000 events. The composite id (activity_{id}
 * / stage_{id} / audit_{id}) keeps rows from different tables collision-free for
 * the frontend's :key.
 */
class DealFeedService
{
    public const TYPE_STAGE_CHANGE = 'stage_change';

    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_FIELD_CHANGE = 'field_change';

    /**
     * Hard upper bound on rows pulled from EACH source before the in-memory
     * merge. Keeps memory/latency bounded for hot entities while the MVP merge
     * stands; a full SQL-UNION + DB-pagination refactor is deferred. The newest
     * rows are kept (each source query orders by created_at desc), so the first
     * pages of the timeline are always complete.
     */
    private const MAX_SOURCE_ROWS = 500;

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
    private function cappedCount(\Illuminate\Database\Eloquent\Builder $query): int
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

        $allowed = [self::TYPE_STAGE_CHANGE, self::TYPE_ACTIVITY, self::TYPE_FIELD_CHANGE];
        $types = array_values(array_intersect($allowed, $raw));

        return $types === [] ? null : $types;
    }

    /**
     * Base query for the stage-change source — the single place the WHERE/ORDER
     * is defined, shared by both the bounded fetch and the capped count so they
     * can never drift apart.
     *
     * @return \Illuminate\Database\Eloquent\Builder<DealStageHistory>
     */
    private function stageQuery(Deal $deal): \Illuminate\Database\Eloquent\Builder
    {
        return DealStageHistory::query()
            ->where('deal_id', $deal->id)
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
     * @return \Illuminate\Database\Eloquent\Builder<Activity>
     */
    private function activityQuery(Deal $deal): \Illuminate\Database\Eloquent\Builder
    {
        return Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $deal->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
                'occurred_at' => $row->created_at?->toIso8601String(),
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
     * @return \Illuminate\Database\Eloquent\Builder<DealAudit>
     */
    private function fieldChangeQuery(Deal $deal): \Illuminate\Database\Eloquent\Builder
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
                    'old_value' => $row->old_value,
                    'new_value' => $row->new_value,
                ],
            ]);
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
