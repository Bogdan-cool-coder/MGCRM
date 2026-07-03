<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/plans/cells (P-2, contract §7.2).
 *
 * authorize() is true — the route is gated by `can:plans.manage` middleware,
 * so no duplicate Policy check is needed here (same pattern as
 * StoreMotivationPlanRequest).
 */
class BulkUpsertCellsRequest extends FormRequest
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
        return [
            'cells' => ['required', 'array', 'min:1', 'max:400'],
            'cells.*.metric' => ['required', 'string', Rule::in(PlanMetric::values())],
            'cells.*.scope_type' => ['required', 'string', Rule::in(PlanScopeType::values())],
            'cells.*.scope_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'cells.*.scope_pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'cells.*.scope_product_group_id' => ['nullable', 'integer', 'exists:catalog_product_groups,id'],
            'cells.*.period_year' => ['required', 'integer', 'between:2020,2100'],
            'cells.*.period_month' => ['nullable', 'integer', 'between:1,12'],
            'cells.*.layer' => ['required', 'string', Rule::in(PlanLayer::values())],
            'cells.*.value_kopecks' => ['nullable', 'integer', 'min:0'],
            'cells.*.value_count' => ['nullable', 'integer', 'min:0'],
            'cells.*.currency' => ['nullable', 'string', 'size:3', Rule::in(config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']))],
            'cells.*.config' => ['nullable', 'array'],
            // metric=tasks_completed
            'cells.*.config.activity_kind' => ['nullable', 'string'],
            // metric=conversion
            'cells.*.config.unit' => ['nullable', 'string', 'in:pct'],
            'cells.*.config.numerator' => ['nullable', 'array'],
            'cells.*.config.numerator.type' => ['nullable', 'string', 'in:task,stage,deal'],
            'cells.*.config.numerator.kind' => ['nullable', 'string'],
            'cells.*.config.numerator.stage_id' => ['nullable', 'integer'],
            'cells.*.config.numerator.status' => ['nullable', 'string'],
            'cells.*.config.denominator' => ['nullable', 'array'],
            'cells.*.config.denominator.type' => ['nullable', 'string', 'in:task,stage,deal'],
            'cells.*.config.denominator.kind' => ['nullable', 'string'],
            'cells.*.config.denominator.stage_id' => ['nullable', 'integer'],
            'cells.*.config.denominator.status' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $cells = $this->input('cells', []);

            if (! is_array($cells)) {
                return;
            }

            foreach ($cells as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $this->validateCell($v, $index, $row);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function validateCell(Validator $v, int|string $index, array $row): void
    {
        $metric = PlanMetric::tryFrom((string) ($row['metric'] ?? ''));
        $scopeType = PlanScopeType::tryFrom((string) ($row['scope_type'] ?? ''));

        if ($metric === null || $scopeType === null) {
            return; // already flagged by the base rules
        }

        // (a) metric x scope compatibility (§3.3)
        if (! $metric->isCompatibleWithScope($scopeType)) {
            $v->errors()->add("cells.{$index}.scope_type", 'Недопустимая комбинация metric/scope_type для данной метрики.');
        }

        $valueKopecks = $row['value_kopecks'] ?? null;
        $valueCount = $row['value_count'] ?? null;
        $currency = $row['currency'] ?? null;

        // (b) exactly one of (value_kopecks+currency) / value_count — OR both
        // null (= delete, contract §O10).
        if ($metric->isMoney()) {
            if ($valueCount !== null) {
                $v->errors()->add("cells.{$index}.value_count", 'Для денежной метрики value_count должен быть пустым.');
            }

            if ($valueKopecks !== null && $currency === null) {
                $v->errors()->add("cells.{$index}.currency", 'currency обязателен, если задано value_kopecks.');
            }
        } else {
            if ($valueKopecks !== null || $currency !== null) {
                $v->errors()->add("cells.{$index}.value_kopecks", 'Для количественной метрики value_kopecks/currency должны быть пустыми.');
            }
        }

        // (c) product_income ⇒ scope_product_group_id required & scope_type=company
        if ($metric === PlanMetric::ProductIncome && ($row['scope_product_group_id'] ?? null) === null) {
            $v->errors()->add("cells.{$index}.scope_product_group_id", 'product_income требует scope_product_group_id.');
        }

        // (d) tasks_completed / conversion ⇒ config present & well-formed
        if (in_array($metric, [PlanMetric::TasksCompleted, PlanMetric::Conversion], true) && empty($row['config'])) {
            $v->errors()->add("cells.{$index}.config", 'Для данной метрики обязателен config.');
        }

        if ($metric === PlanMetric::TasksCompleted && empty($row['config']['activity_kind'] ?? null)) {
            $v->errors()->add("cells.{$index}.config.activity_kind", 'tasks_completed требует config.activity_kind.');
        }

        if ($metric === PlanMetric::Conversion) {
            if (empty($row['config']['numerator'] ?? null) || empty($row['config']['denominator'] ?? null)) {
                $v->errors()->add("cells.{$index}.config", 'conversion требует config.numerator и config.denominator.');
            }
        }

        // (e) the correct scope FK is set for the declared scope_type, others null
        $this->assertExactScopeFk($v, $index, $scopeType, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertExactScopeFk(Validator $v, int|string $index, PlanScopeType $scopeType, array $row): void
    {
        $userId = $row['scope_user_id'] ?? null;
        $pipelineId = $row['scope_pipeline_id'] ?? null;

        if ($scopeType === PlanScopeType::User) {
            if ($userId === null) {
                $v->errors()->add("cells.{$index}.scope_user_id", 'scope_type=user требует scope_user_id.');
            }

            if ($pipelineId !== null) {
                $v->errors()->add("cells.{$index}.scope_pipeline_id", 'scope_pipeline_id должен быть пустым при scope_type=user.');
            }
        }

        if ($scopeType === PlanScopeType::Pipeline) {
            if ($pipelineId === null) {
                $v->errors()->add("cells.{$index}.scope_pipeline_id", 'scope_type=pipeline требует scope_pipeline_id.');
            }

            if ($userId !== null) {
                $v->errors()->add("cells.{$index}.scope_user_id", 'scope_user_id должен быть пустым при scope_type=pipeline.');
            }
        }

        if ($scopeType === PlanScopeType::Company && ($userId !== null || $pipelineId !== null)) {
            $v->errors()->add("cells.{$index}.scope_type", 'scope_user_id/scope_pipeline_id должны быть пустыми при scope_type=company.');
        }
    }
}
