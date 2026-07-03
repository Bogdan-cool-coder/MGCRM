<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/reports/registry (R1, contract §6.4/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (ExpectedIncomeRegistryService), not gated by a permission (contract §8.2).
 */
class RegistryReportRequest extends FormRequest
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
            'month' => ['required', 'integer', 'between:1,12'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'product_group_id' => ['nullable', 'integer', 'exists:catalog_product_groups,id'],
        ];
    }
}
