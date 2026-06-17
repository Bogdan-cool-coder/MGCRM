<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deal */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'amount' => $this->amount, // kopecks
            'currency' => $this->currency,
            'status' => $this->status(),

            'pipeline_id' => $this->pipeline_id,
            'pipeline' => $this->whenLoaded('pipeline', fn () => [
                'id' => $this->pipeline->id,
                'name' => $this->pipeline->name,
                'kind' => $this->pipeline->kind?->value,
            ]),
            'stage_id' => $this->stage_id,
            'stage' => $this->whenLoaded('stage', fn () => new PipelineStageResource($this->stage)),

            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),

            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->full_name,
            ]),

            'department_id' => $this->department_id,
            'contract_id' => $this->contract_id,

            'tags' => $this->tags ?? [],
            'extra_fields' => $this->extra_fields ?? [],

            'lost_reason' => $this->lost_reason,
            'lost_reason_id' => $this->lost_reason_id,

            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'expected_sign_date' => $this->expected_sign_date?->toDateString(),
            'expected_payment_date' => $this->expected_payment_date?->toDateString(),

            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),

            // Whole days in the current stage — rotting-clock base for the header
            // (DealPage 2.0 v2 §3.2). Pure compute, always present.
            'days_in_stage' => $this->daysInStage(),

            // Soonest open task on this deal — the header health chip (DealPage
            // 2.0 v2 §8 v2-B1). Same shape as the Kanban card's next_task.
            'next_task' => $this->whenLoaded('nextTask', fn () => $this->nextTaskPayload()),

            'products' => DealProductResource::collection($this->whenLoaded('products')),
            'contacts' => DealContactResource::collection($this->whenLoaded('dealContacts')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Build the next_task chip payload from the loaded nextTask relation. Same
     * shape as DealCardResource.next_task (id/type/title/due_at/is_overdue) so
     * the Kanban card and the DealPage header chip read one contract.
     *
     * @return array{id: int, type: string, title: string, due_at: ?string, is_overdue: bool}|null
     */
    private function nextTaskPayload(): ?array
    {
        $task = $this->nextTask;

        if ($task === null) {
            return null;
        }

        return [
            'id' => $task->id,
            'type' => $task->kind?->value,
            'title' => $task->title,
            'due_at' => $task->due_at?->toIso8601String(),
            'is_overdue' => $task->isOverdue(),
        ];
    }
}
