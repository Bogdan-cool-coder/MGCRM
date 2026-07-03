<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Enums\PlanLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for GET /api/reports/product-income (R6, contract §6.9/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (ProductIncomeService), not gated by a permission (contract §8.2).
 */
class ProductIncomeReportRequest extends FormRequest
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
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
        ];
    }
}
