<?php

declare(strict_types=1);

namespace App\Http\Resources\Activity;

use App\Domain\Activity\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
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
            'progress_pct' => $this->progress_pct,
            'is_closed' => $this->is_closed,
            'is_overdue' => $this->isOverdue(),
            'is_pinned' => $this->is_pinned,
            'result_text' => $this->result_text,

            // FTM (chiefly for meeting kind).
            'is_first_time_meeting' => $this->is_first_time_meeting,
            'ftm_decision_maker_attended' => $this->ftm_decision_maker_attended,
            'ftm_presentation_shown' => $this->ftm_presentation_shown,
            'ftm_report_url' => $this->ftm_report_url,

            'meeting_report_json' => $this->meeting_report_json,

            'responsible_id' => $this->responsible_id,
            'responsible' => $this->whenLoaded('responsible', fn () => [
                'id' => $this->responsible->id,
                'name' => $this->responsible->full_name,
            ]),

            'created_by_id' => $this->created_by_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->full_name,
            ]),

            'completed_by_id' => $this->completed_by_id,
            'completed_by' => $this->whenLoaded('completedBy', fn () => [
                'id' => $this->completedBy->id,
                'name' => $this->completedBy->full_name,
            ]),

            'department_id' => $this->department_id,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
