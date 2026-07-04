<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Activity\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Activity feed item for GET /api/me/activity-feed (S1.8).
 * The ftm_counted flag is computed here using the shared ftmCounted() logic
 * that matches the KPI count — single source of truth (risk Н from plan).
 */
class ActivityFeedItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind instanceof \BackedEnum ? $this->kind->value : $this->kind,
            'title' => $this->title,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            // The linked deal/contact/company's display name (ГЭП-1,
            // ManagerCabinet-v2-spec §7), batched onto the Activity model by
            // ActivityService::feedForUser()/stampTargetNames() — never a
            // per-item lookup. null for a standalone activity, an unrecognised
            // target type, or a target that no longer exists (soft
            // degradation); the frontend falls back to "{label} #{id}".
            'target_name' => $this->getAttribute('target_name'),
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_first_time_meeting' => (bool) $this->is_first_time_meeting,
            'ftm_counted' => $this->ftmCounted(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Compute the ftm_counted flag (5 conditions — plan §Б2). Delegates to the
     * single source Activity::qualifiesAsFtm() so the flag is never out of sync
     * with the KPI count.
     */
    private function ftmCounted(): bool
    {
        return Activity::qualifiesAsFtm($this->resource);
    }
}
