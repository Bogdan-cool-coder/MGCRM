<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use App\Domain\Notification\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * NotificationFeedResource — the grouped flyout envelope (GET /api/notifications).
 *
 * Wraps the NotificationService::grouped() output into one JSON document:
 *   - actionable: "needs attention" (is_actionable && unread), not paginated
 *   - feed:       the chronological tail, with pagination meta
 *   - digest:     unread counters (total + per-category)
 *   - unread_count
 *
 * Top-level document (no outer "data" envelope) so the flyout reads
 * actionable/feed/digest directly. We use the per-CLASS static override
 * ($wrap = null) — ResourceResponse reads get_class($resource)::$wrap via late
 * static binding, so ONLY this resource is unwrapped; NotificationResource
 * keeps the inherited 'data' wrapper (same pattern as DashboardResource, HD3).
 *
 * @property array{
 *     actionable: Collection<int, Notification>,
 *     feed: LengthAwarePaginator<int, Notification>,
 *     digest: array{unread_total: int, by_category: array<string, int>},
 *     unread_count: int
 * } $resource
 */
class NotificationFeedResource extends JsonResource
{
    /** Per-class wrapper override — only this resource is unwrapped (HD3). */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Notification> $actionable */
        $actionable = $this->resource['actionable'];

        /** @var LengthAwarePaginator<int, Notification> $feed */
        $feed = $this->resource['feed'];

        /** @var array{unread_total: int, by_category: array<string, int>} $digest */
        $digest = $this->resource['digest'];

        return [
            'actionable' => NotificationResource::collection($actionable)->resolve($request),
            'feed' => [
                'data' => NotificationResource::collection($feed->getCollection())->resolve($request),
                'meta' => [
                    'current_page' => $feed->currentPage(),
                    'last_page' => $feed->lastPage(),
                    'per_page' => $feed->perPage(),
                    'total' => $feed->total(),
                ],
            ],
            'digest' => [
                'unread_total' => $digest['unread_total'],
                // Cast to object so an empty breakdown serializes as {} (a JSON
                // object) rather than [] — keeps the `Record<string, number>`
                // contract honest for the FE digest chips.
                'by_category' => (object) $digest['by_category'],
            ],
            'unread_count' => $this->resource['unread_count'],
        ];
    }
}
