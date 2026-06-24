<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealAmountCalculator;
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
            // kopecks — NET: the canonical deal value with discount_percent already
            // folded into the line-item sum (DealService::recalcAmount). Every money
            // aggregate (board/list/KPI/company/contact/export) reads this directly.
            // When amount_locked is true it is a fixed budget instead.
            'amount' => $this->amount,
            // Deal-level discount in PERCENT (0..50). Already folded into `amount`
            // above; also applied uniformly to the line items to derive
            // products_net_total. The per-line discount on each product (kopecks) is
            // separate. Always present (defaults to 0).
            'discount_percent' => (int) ($this->discount_percent ?? 0),
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

            // ---- Deals-list columns (SalesFunnel-spec §5.2). Read-only, derived
            // from the loaded company / batched activity stamp — never $fillable.

            // B1 «Страна» — the deal's company country (ISO-2, e.g. "kz"). Read off
            // the eager-loaded company (list() selects country_code); null when the
            // company relation is not loaded or the company has no country.
            'country' => $this->whenLoaded('company', fn (): ?string => $this->company?->country_code),

            // B3 «Категории L/M/S» — the raw company category code (L/M/S1/S2). The
            // frontend aggregates S = S1 + S2 for the KPI chip; the backend stays
            // un-opinionated and ships the raw code. CategoryCode enum → its string
            // value. null when uncategorised or the relation is not loaded.
            'category' => $this->whenLoaded('company', fn (): ?string => $this->company?->category_code?->value),

            // B2 «Посл. контакт» — ISO-8601 date of the last COMPLETED client-facing
            // event on the deal (call/follow-up/meeting/presentation). Batched onto
            // the model by DealService::list() (last_contact_at_payload); null on
            // single-deal renders (show/store/update) where it is not stamped.
            'last_contact_at' => $this->resource->getAttribute('last_contact_at_payload'),

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

            // Actual paid sum (kopecks) + its currency — distinct from amount/currency.
            'paid_amount' => $this->paid_amount,
            'payment_currency' => $this->payment_currency,

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
            // 2.0 v2 §8 v2-B1). Same shape as the Kanban card's next_task. Read from
            // the eager-loaded nextTask relation (single-deal card) OR the batched
            // next_task_payload attribute stamped by DealService::list() on the
            // kanban "load more" path (audit m10) — whichever is present.
            'next_task' => $this->resolveNextTask(),

            // Board-card primary product {id, name} | null — stamped (possibly null)
            // by DealService on the kanban "load more" list path (audit m10).
            // offsetExists is true only when the attribute was actually stamped, so
            // the key is OMITTED on every other render (plain list/show) and present
            // (value or null) only for load-more cards — the FE then shows it or the
            // "no product" placeholder.
            $this->mergeWhen(
                $this->resource->offsetExists('primary_product_payload'),
                fn (): array => ['primary_product' => $this->resource->getAttribute('primary_product_payload')],
            ),

            'products' => DealProductResource::collection($this->whenLoaded('products')),
            // Sum of per-line discounts (kopecks). Computed from the already
            // loaded products relation — no extra query (ARCHITECTURE.md §3 N+1).
            'discount_total' => $this->whenLoaded(
                'products',
                fn (): int => (int) $this->products->sum('discount'),
            ),
            // Recomputed line totals after applying the deal-level discount_percent
            // uniformly to each (already per-line-discounted) product amount. All
            // kopecks; the deal-level percent is applied AFTER the per-line discount.
            // products_gross_total = Σ line.amount (PRE deal-level discount).
            // products_net_total   = Σ round(line.amount * (1 - pct/100))
            //                        (== Deal.amount when unlocked — the canonical
            //                        NET value; see DealService::recalcAmount).
            // products_discounted  = [{ id, net_amount }] so the FE can render the
            //   discounted price under each line. Only when products are loaded.
            $this->mergeWhen($this->resource->relationLoaded('products'), fn (): array => $this->discountedTotals()),
            'contacts' => DealContactResource::collection($this->whenLoaded('dealContacts')),

            // Deal-card «Активность» tab metrics block (six figures). Stamped onto
            // the model as `metrics_payload` by DealController::show() ONLY — absent
            // on list/board/store/update payloads (the key is then omitted, never a
            // half-computed null). See DealService::metricsFor().
            $this->mergeWhen(
                $this->resource->getAttribute('metrics_payload') !== null,
                fn (): array => ['metrics' => $this->resource->getAttribute('metrics_payload')],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Recompute the product line totals after applying the deal-level
     * discount_percent uniformly. Each line's net amount = round(line.amount *
     * (1 - pct/100)) in integer kopecks (the per-line discount is already baked
     * into line.amount). Returns:
     *   products_gross_total — Σ line.amount (pre deal-level discount, kopecks),
     *   products_net_total   — Σ per-line net amounts (post deal-level discount),
     *   products_discounted  — [{ id, net_amount }] for per-line FE rendering.
     *
     * pct = 0 → net == gross. Computed off the already-loaded products relation
     * (no extra query). Rounding is per line (consistent with each row's display)
     * then summed, so the grand total equals the sum of the shown line prices.
     *
     * @return array{
     *     products_gross_total: int,
     *     products_net_total: int,
     *     products_discounted: list<array{id: int, net_amount: int}>,
     * }
     */
    private function discountedTotals(): array
    {
        // Same calculator that DealService::recalcAmount uses to persist
        // deals.amount — so products_net_total can never drift from the canonical
        // (now NET) deals.amount. Resolved via app() (Resources are not DI'd).
        $calculator = app(DealAmountCalculator::class);
        $pct = $calculator->clampPercent($this->discount_percent ?? 0);

        $gross = 0;
        $net = 0;
        $perLine = [];

        foreach ($this->products as $line) {
            $lineGross = (int) $line->amount;
            $lineNet = $calculator->applyPercent($lineGross, $pct);

            $gross += $lineGross;
            $net += $lineNet;

            $perLine[] = [
                'id' => (int) $line->id,
                'net_amount' => $lineNet,
            ];
        }

        return [
            'products_gross_total' => $gross,
            'products_net_total' => $net,
            'products_discounted' => $perLine,
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
     * Resolve the next_task chip payload from whichever source is present: the
     * eager-loaded nextTask relation (single-deal card) or the batched
     * next_task_payload attribute stamped by DealService::list() on the kanban
     * "load more" path (audit m10). Returns null when neither is set so the list
     * view (which loads neither) keeps emitting `next_task: null` unchanged.
     *
     * @return array{id: int, type: string, title: string, due_at: ?string, is_overdue: bool}|null
     */
    private function resolveNextTask(): ?array
    {
        if ($this->resource->relationLoaded('nextTask')) {
            return $this->nextTaskPayload();
        }

        $stamped = $this->resource->getAttribute('next_task_payload');

        return is_array($stamped) ? $stamped : null;
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
