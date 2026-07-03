<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for GET /api/reports/conversions (R5, contract §6.8/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (ConversionReportService), not gated by a permission (contract §8.2).
 */
class ConversionReportRequest extends FormRequest
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
            'year' => ['required', 'integer', 'between:2020,2100'],
            'layer' => ['required', 'string', Rule::in(PlanLayer::values())],
            // Conversion metric is only valid for user/pipeline scope (contract
            // §3.3) — company is excluded here (would 422 downstream anyway).
            'scope_type' => ['nullable', 'string', Rule::in([PlanScopeType::User->value, PlanScopeType::Pipeline->value])],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
        ];
    }
}
