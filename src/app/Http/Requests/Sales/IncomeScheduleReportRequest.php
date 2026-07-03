<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/reports/income-schedule (R2, contract §6.5/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (IncomeScheduleService), not gated by a permission (contract §8.2).
 */
class IncomeScheduleReportRequest extends FormRequest
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
        ];
    }
}
