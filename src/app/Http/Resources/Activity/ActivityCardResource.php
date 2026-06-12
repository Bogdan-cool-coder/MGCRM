<?php

declare(strict_types=1);

namespace App\Http\Resources\Activity;

use App\Domain\Activity\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight Activity payload for timeline cards and the "My tasks" list —
 * omits the heavy meeting_report_json/result_text fields.
 *
 * @mixin Activity
 */
class ActivityCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'title' => $this->title,
            'due_at' => $this->due_at?->toIso8601String(),
            'status' => $this->status?->value,
            'priority' => $this->priority?->value,
            'is_closed' => $this->is_closed,
            'is_overdue' => $this->isOverdue(),
            'is_pinned' => $this->is_pinned,
            'responsible_id' => $this->responsible_id,
            'responsible' => $this->whenLoaded('responsible', fn () => [
                'id' => $this->responsible->id,
                'name' => $this->responsible->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
