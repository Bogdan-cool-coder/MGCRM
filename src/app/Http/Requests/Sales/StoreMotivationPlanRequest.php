<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Enums\MotivationCardItemKind;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/admin/motivation/plans (contract §7.1).
 *
 * authorize() is true — the route is gated by `can:motivation.manage`
 * middleware, so no duplicate Policy check is needed here.
 */
class StoreMotivationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kinds = array_map(static fn (MotivationCardItemKind $k): string => $k->value, MotivationCardItemKind::cases());

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'base_currency' => ['required', 'string', 'size:3'],
            'supervisor_user_id' => ['nullable', 'integer', 'exists:users,id'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.kind' => ['required', 'string', Rule::in($kinds)],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.plan_amount_kopecks' => ['nullable', 'integer', 'min:0'],
            'items.*.fact_amount_kopecks' => ['nullable', 'integer', 'min:0'],
            'items.*.salary_plan_kopecks' => ['nullable', 'integer', 'min:0'],
            'items.*.salary_fact_kopecks' => ['nullable', 'integer', 'min:0'],
            'items.*.currency' => ['required', 'string', 'size:3'],
            'items.*.sort' => ['nullable', 'integer'],
            // `params` is a flexible per-kind jsonb bag (contract §2.2.1). Laravel's
            // validated() SILENTLY DROPS any nested key without an explicit rule —
            // even under a `nullable array` parent — so every legitimate key the
            // frontend/engine writes for ANY kind must be listed here explicitly
            // (BUG-KPI-PLAN-COUNT-LOST: plan_count was missing and vanished on save).
            'items.*.params' => ['nullable', 'array'],
            // kind=commission
            'items.*.params.rate_pct_times_100' => ['nullable', 'integer', 'between:1,10000'],
            'items.*.params.condition' => ['nullable', 'string', 'max:255'],
            'items.*.params.payment_note' => ['nullable', 'string', 'max:255'],
            'items.*.params.breakdown' => ['nullable', 'array'],
            'items.*.params.breakdown.*.deal_id' => ['nullable', 'integer'],
            'items.*.params.breakdown.*.company_name' => ['nullable', 'string', 'max:255'],
            'items.*.params.breakdown.*.amount_kopecks' => ['nullable', 'integer'],
            'items.*.params.breakdown.*.currency' => ['nullable', 'string', 'size:3'],
            // kind=kpi (§9 ОВ-5 — flexible KPI)
            'items.*.params.kpi_type' => ['nullable', 'string', 'in:count,amount,manual'],
            'items.*.params.unit' => ['nullable', 'string', 'max:64'],
            'items.*.params.salary_per_completion_kopecks' => ['nullable', 'integer', 'min:0'],
            'items.*.params.manual_done' => ['nullable', 'boolean'],
            'items.*.params.plan_count' => ['nullable', 'integer', 'min:0'],
            'items.*.params.fact_count' => ['nullable', 'integer', 'min:0'],
            // kind=team_kpi (denormalised snapshot — usually engine-written, but
            // accepted here too so a round-trip edit never drops it)
            'items.*.params.team_kpi_rule_id' => ['nullable', 'integer'],
            'items.*.params.split_contribution_pct' => ['nullable', 'integer', 'between:0,100'],
            'items.*.params.split_equal_pct' => ['nullable', 'integer', 'between:0,100'],
            'items.*.params.min_threshold_pct' => ['nullable', 'integer', 'between:1,100'],
            'items.*.params.gate_passed' => ['nullable', 'boolean'],
            'items.*.params.dept_pct' => ['nullable', 'integer'],
            'items.*.params.part1_kopecks' => ['nullable', 'integer'],
            'items.*.params.part2_kopecks' => ['nullable', 'integer'],
            // kind=bonus
            'items.*.params.reason' => ['nullable', 'string', 'max:500'],

            'team_kpi_rule' => ['nullable', 'array'],
            'team_kpi_rule.pipeline_id' => ['required_with:team_kpi_rule', 'integer', 'exists:pipelines,id'],
            'team_kpi_rule.team_income_target_kopecks' => ['nullable', 'integer', 'min:0'],
            'team_kpi_rule.target_currency' => ['nullable', 'string', 'size:3'],
            'team_kpi_rule.base_pool_kopecks' => ['nullable', 'integer', 'min:0'],
            'team_kpi_rule.per_extra_member_kopecks' => ['nullable', 'integer', 'min:0'],
            'team_kpi_rule.min_members' => ['nullable', 'integer', 'min:1'],
            'team_kpi_rule.pool_currency' => ['nullable', 'string', 'size:3'],
            'team_kpi_rule.split_contribution_pct' => ['nullable', 'integer', 'between:0,100'],
            'team_kpi_rule.split_equal_pct' => ['nullable', 'integer', 'between:0,100'],
            'team_kpi_rule.min_threshold_pct' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rule = $this->input('team_kpi_rule');

            if (! is_array($rule)) {
                return;
            }

            $contribution = (int) ($rule['split_contribution_pct'] ?? 60);
            $equal = (int) ($rule['split_equal_pct'] ?? 40);

            if ($contribution + $equal !== 100) {
                $v->errors()->add(
                    'team_kpi_rule.split_contribution_pct',
                    'Сумма split_contribution_pct и split_equal_pct должна быть равна 100.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Выберите менеджера.',
            'items.required' => 'Добавьте хотя бы одну строку карты.',
            'items.min' => 'Добавьте хотя бы одну строку карты.',
            'base_currency.size' => 'Валюта должна быть в формате ISO-4217 (3 буквы).',
        ];
    }
}
