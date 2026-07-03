<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Sales\Enums\PlanLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for GET /api/reports/task-matrix (R4, contract §6.7/§7.4).
 *
 * authorize(): true — read is visibility-scoped inside the aggregator
 * (TaskMatrixService), not gated by a permission (contract §8.2).
 */
class TaskMatrixReportRequest extends FormRequest
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
            'kind' => ['nullable', 'string', Rule::in([...ActivityType::taskLikeValues(), 'ftm'])],
        ];
    }
}
