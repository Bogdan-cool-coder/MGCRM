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
 * Validation for GET /api/plans/matrix (P-1, contract §7.1).
 *
 * authorize() is true — read is visibility-scoped inside PlanTargetService,
 * not gated by a permission (contract §8.2: any authed user may call it).
 */
class PlanMatrixRequest extends FormRequest
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
            'scope_type' => ['required', 'string', Rule::in(PlanScopeType::values())],
            'layer' => ['required', 'string', Rule::in(PlanLayer::values())],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
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
        });
    }
}
