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
     * @param  array{types?: array<int, string>}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int}}
     */
    public function feed(Deal $deal, array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $types = $this->normaliseTypes($filters['types'] ?? null);

        $events = collect();

        if ($types === null || in_array(self::TYPE_STAGE_CHANGE, $types, true)) {
            $events = $events->merge($this->stageEvents($deal));
        }

        if ($types === null || in_array(self::TYPE_ACTIVITY, $types, true)) {
            $events = $events->merge($this->activityEvents($deal));
        }

        if ($types === null || in_array(self::TYPE_FIELD_CHANGE, $types, true)) {
            $events = $events->merge($this->fieldChangeEvents($deal));
        }

        $sorted = $events
            ->sortByDesc(fn (array $event): string => $event['occurred_at'] ?? '')
            ->values();

        $total = $sorted->count();
        $page = max(1, $page);
        $perPage = max(1, $perPage);

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
     * @return Collection<int, array<string, mixed>>
     */
    private function stageEvents(Deal $deal): Collection
    {
        return DealStageHistory::query()
            ->where('deal_id', $deal->id)
            ->with(['fromStage:id,name', 'toStage:id,name', 'user:id,full_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
     * Activities are polymorphic without FK; the deal is matched on
     * target_type + target_id (DDD §2 — no belongsTo Deal relation).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activityEvents(Deal $deal): Collection
    {
        return Activity::query()
            ->where('target_type', ActivityTargetType::Deal->value)
            ->where('target_id', $deal->id)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
                    'is_closed' => (bool) $row->is_closed,
                    'responsible' => $this->actor($row->responsible),
                ],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fieldChangeEvents(Deal $deal): Collection
    {
        return DealAudit::query()
            ->where('deal_id', $deal->id)
            ->with('user:id,full_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
