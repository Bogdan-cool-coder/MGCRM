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
            'body' => $this->body,
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'status' => $this->status?->value,
            'priority' => $this->priority?->value,
            'is_closed' => $this->is_closed,
            'is_overdue' => $this->isOverdue(),
            'is_pinned' => $this->is_pinned,
            'responsible_id' => $this->responsible_id,
            'responsible' => $this->whenLoaded('responsible', fn () => [
                'id' => $this->responsible->id,
                'full_name' => $this->responsible->full_name,
            ]),
            // Parent deal context (id + title + stage + company) when the task
            // targets a deal — batch-stamped by ActivityService (myBoard/list). null
            // for company/contact/standalone targets.
            'deal' => $this->dealContext(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The batched deal-context attribute stamped by ActivityService. Returns null
     * when not resolved or when the target is not a deal.
     *
     * @return array<string, mixed>|null
     */
    private function dealContext(): ?array
    {
        $context = $this->resource->getAttribute('deal_context');

        return is_array($context) ? $context : null;
    }
}
