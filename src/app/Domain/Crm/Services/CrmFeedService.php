<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;

/**
 * CrmFeedService — unified chronological activity timeline for a Company or Contact.
 *
 * Mirrors DealFeedService (Sales domain) but scopes activities by target_type
 * (ActivityTargetType::Company / ActivityTargetType::Contact) and target_id.
 *
 * MVP: only activities are included (no audit trail for CRM entities yet).
 * Shape is identical to the deal feed so the frontend can reuse the same
 * normalisation logic in useEntityFeed.ts.
 *
 * @see DealFeedService
 */
class CrmFeedService
{
    public const TYPE_ACTIVITY = 'activity';

    /**
     * Hard upper bound on rows pulled from each source before the in-memory
     * merge. Bounds memory/latency for hot entities (newest rows kept).
     */
    private const MAX_SOURCE_ROWS = 500;

    /**
     * @param  array{types?: array<int, string>}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int}}
     */
    public function feed(Company|Contact $entity, array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $types = $this->normaliseTypes($filters['types'] ?? null);

        $events = collect();

        if ($types === null || in_array(self::TYPE_ACTIVITY, $types, true)) {
            $events = $events->merge($this->activityEvents($entity));
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
     * @return array<int, string>|null
     */
    private function normaliseTypes(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $allowed = [self::TYPE_ACTIVITY];
        $types = array_values(array_intersect($allowed, $raw));

        return $types === [] ? null : $types;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activityEvents(Company|Contact $entity): Collection
    {
        $targetType = $entity instanceof Company
            ? ActivityTargetType::Company->value
            : ActivityTargetType::Contact->value;

        return Activity::query()
            ->where('target_type', $targetType)
            ->where('target_id', $entity->id)
            ->with(['responsible:id,full_name', 'createdBy:id,full_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SOURCE_ROWS)
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
                    'target_type' => $targetType,
                    'responsible' => $this->actor($row->responsible),
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
