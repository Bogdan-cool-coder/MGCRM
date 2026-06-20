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
            // When true, amount is a fixed budget and is NOT re-derived from line
            // items (it may differ from sum(products) by design).
            'amount_locked' => (bool) $this->amount_locked,
            'currency' => $this->currency,
            // «Вечная лицензия» / «Коробка / on-premise» (price logic in N4).
            'perpetual_license' => (bool) $this->perpetual_license,
            // N5 client-lifecycle flags. is_primary_deal = the first won deal that
            // made the company a unique client. is_upsell is DERIVED, never stored
            // (won on an already-converted company); a non-won deal is neither.
            'is_primary_deal' => (bool) $this->is_primary_deal,
            'is_upsell' => $this->status() === 'won' && ! $this->is_primary_deal,
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

            // Actual fact dates — the «Факт» half of the «План / Факт» pairs.
            'signed_at' => $this->signed_at?->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),

            'kp_sent_at' => $this->kp_sent_at?->toIso8601String(),
            'contract_sent_at' => $this->contract_sent_at?->toIso8601String(),
            'max_stage_id' => $this->max_stage_id,

            // Deal-card header "ключевые действия" — six { type, date|null, ref? }
            // entries. A null date means the action has not happened (the
            // frontend hides the icon). Always present so the header can render a
            // fixed icon row. See keyActions().
            'key_actions' => $this->keyActions(),

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
            // Sum of per-line discounts (kopecks). Computed from the already
            // loaded products relation — no extra query (ARCHITECTURE.md §3 N+1).
            'discount_total' => $this->whenLoaded(
                'products',
                fn (): int => (int) $this->products->sum('discount'),
            ),
            'contacts' => DealContactResource::collection($this->whenLoaded('dealContacts')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The deal-card header "ключевые действия" block: six entries, each
     * { type, date|null, ref? }. Order is the header's icon order. A null date =
     * the action never happened (the frontend hides the icon).
     *
     * Sources:
     *   - last_presentation / last_touch / last_event — derived live from the
     *     Activity timeline, stamped onto the model as `key_action_dates` by the
     *     controller (DealController::show); absent on list payloads → null dates.
     *   - max_stage — the loaded maxStage relation (id/name/color in `ref`).
     *   - kp_sent / contract_sent — the deal's own columns.
     *
     * @return list<array{type: string, date: ?string, ref?: array<string, mixed>}>
     */
    private function keyActions(): array
    {
        $dates = $this->resource->getAttribute('key_action_dates') ?? [];

        return [
            [
                'type' => 'last_presentation',
                'date' => $dates['last_presentation_at'] ?? null,
            ],
            [
                'type' => 'max_stage',
                'date' => null,
                'ref' => $this->maxStageRef(),
            ],
            [
                'type' => 'kp_sent',
                'date' => $this->kp_sent_at?->toIso8601String(),
            ],
            [
                'type' => 'contract_sent',
                'date' => $this->contract_sent_at?->toIso8601String(),
            ],
            [
                'type' => 'last_touch',
                'date' => $dates['last_touch_at'] ?? null,
            ],
            [
                'type' => 'last_event',
                'date' => $dates['last_event_at'] ?? null,
            ],
        ];
    }

    /**
     * The max_stage `ref` payload {stage_id, name, color}, or null when the deal
     * has no recorded high-water mark or the maxStage relation is not loaded (the
     * frontend then hides the chip). Reads the loaded relation — no extra query.
     *
     * @return array{stage_id: int, name: string, color: ?string}|null
     */
    private function maxStageRef(): ?array
    {
        if ($this->max_stage_id === null || ! $this->resource->relationLoaded('maxStage')) {
            return null;
        }

        $stage = $this->maxStage;

        if ($stage === null) {
            return null;
        }

        return [
            'stage_id' => (int) $stage->id,
            'name' => $stage->name,
            'color' => $stage->color,
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
