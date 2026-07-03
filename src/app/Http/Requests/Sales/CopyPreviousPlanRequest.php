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
 * Validation for POST /api/plans/copy-previous (P-3, contract §7.3).
 *
 * authorize() is true — route gated by `can:plans.manage` middleware.
 */
class CopyPreviousPlanRequest extends FormRequest
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
            'metric' => ['required', 'string', Rule::in(PlanMetric::values())],
            'layer' => ['required', 'string', Rule::in(PlanLayer::values())],
            'scope_type' => ['required', 'string', Rule::in(PlanScopeType::values())],
            'from_year' => ['required', 'integer', 'between:2020,2100'],
            'to_year' => ['required', 'integer', 'between:2020,2100'],
            'from_month' => ['nullable', 'integer', 'between:1,12'],
            'to_month' => ['nullable', 'integer', 'between:1,12'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $metric = $this->string('metric')->toString();
            $scopeType = $this->string('scope_type')->toString();

            if ($metric === '' || $scopeType === '' || PlanMetric::tryFrom($metric) === null || PlanScopeType::tryFrom($scopeType) === null) {
                return;
            }

            if (! PlanMetric::from($metric)->isCompatibleWithScope(PlanScopeType::from($scopeType))) {
                $v->errors()->add('scope_type', 'Недопустимая комбинация metric/scope_type для данной метрики.');
            }

            $fromMonth = $this->input('from_month');
            $toMonth = $this->input('to_month');

            if (($fromMonth === null) !== ($toMonth === null)) {
                $v->errors()->add('to_month', 'from_month и to_month должны быть заданы вместе, либо оба пустыми (копирование целого года).');
            }
        });
    }
}
