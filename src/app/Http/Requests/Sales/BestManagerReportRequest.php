<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/reports/best-manager (R3, contract §6.6/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (BestManagerService), not gated by a permission (contract §8.2).
 */
class BestManagerReportRequest extends FormRequest
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
            'mode' => ['nullable', 'string', 'in:standard,absolute'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
        ];
    }
}
