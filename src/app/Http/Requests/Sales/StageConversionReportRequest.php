<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/reports/stage-conversions (R5 `stage` block as a
 * standalone endpoint, contract §6.8 improvement #3). `pipeline_id` is
 * REQUIRED — a stage chain is meaningless across pipelines.
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (StageConversionService), not gated by a permission (contract §8.2).
 */
class StageConversionReportRequest extends FormRequest
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
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
