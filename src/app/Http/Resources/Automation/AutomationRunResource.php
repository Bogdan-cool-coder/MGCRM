<?php

declare(strict_types=1);

namespace App\Http\Resources\Automation;

use App\Domain\Automation\Models\AutomationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AutomationRunResource — one audit row for the runs journal.
 *
 * Manual resource (ARCHITECTURE §1). The journal denormalises the parent
 * automation name + action_kind (eager-loaded by AutomationRunQueryService with
 * a column subset, so no N+1) so the UI can render the table without a second
 * fetch. status/result/error_message tell the operator what happened and back
 * the "Retry" button on a failed run.
 *
 * @mixin AutomationRun
 */
class AutomationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'automation_name' => $this->whenLoaded('automation', fn (): ?string => $this->automation?->name),
            'action_kind' => $this->whenLoaded('automation', fn (): ?string => $this->automation?->action_kind?->value),
            'target_type' => $this->target_type->value,
            'target_id' => $this->target_id,
            'status' => $this->status->value,
            'trigger_event_ts' => $this->trigger_event_ts?->toIso8601String(),
            'result' => $this->result,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
