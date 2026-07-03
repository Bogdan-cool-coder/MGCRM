<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\TeamKpiRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MotivationPlanResource — the editable constructor shape (contract §6.2/§6.3):
 * GET /api/admin/motivation/plans/{userId}/{year}/{month} and the response of
 * POST /api/admin/motivation/plans. Editable fields only — no computed
 * salary_fact for empty facts (that belongs to MotivationCardResource / the
 * cabinet read).
 *
 * @mixin MotivationCard
 */
class MotivationPlanResource extends JsonResource
{
    /**
     * @param  TeamKpiRule|null  $teamKpiRule  the pipeline+period team rule, resolved by the caller
     */
    public function __construct(MotivationCard $resource, private readonly ?TeamKpiRule $teamKpiRule = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'pipeline_id' => $this->pipeline_id,
            'year' => $this->period_year,
            'month' => $this->period_month,
            'status' => $this->status->value,
            'base_currency' => $this->base_currency,
            'fact_source' => $this->fact_source->value,
            'supervisor_user_id' => $this->supervisor_user_id,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(static fn ($item): array => [
                'id' => $item->id,
                'kind' => $item->kind->value,
                'name' => $item->name,
                'plan_amount_kopecks' => $item->plan_amount_kopecks,
                'fact_amount_kopecks' => $item->fact_amount_kopecks,
                'salary_plan_kopecks' => $item->salary_plan_kopecks,
                'salary_fact_kopecks' => $item->salary_fact_kopecks,
                'currency' => $item->currency,
                'params' => $item->params,
                'sort' => $item->sort,
            ])->all()),
            'team_kpi_rule' => $this->teamKpiRule === null ? null : [
                'pipeline_id' => $this->teamKpiRule->pipeline_id,
                'team_income_target_kopecks' => $this->teamKpiRule->team_income_target_kopecks,
                'target_currency' => $this->teamKpiRule->target_currency,
                'base_pool_kopecks' => $this->teamKpiRule->base_pool_kopecks,
                'per_extra_member_kopecks' => $this->teamKpiRule->per_extra_member_kopecks,
                'min_members' => $this->teamKpiRule->min_members,
                'pool_currency' => $this->teamKpiRule->pool_currency,
                'split_contribution_pct' => $this->teamKpiRule->split_contribution_pct,
                'split_equal_pct' => $this->teamKpiRule->split_equal_pct,
                'min_threshold_pct' => $this->teamKpiRule->min_threshold_pct,
            ],
        ];
    }
}
