<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest for HR-dashboard filter parameters (S3.7).
 *
 * All fields optional. Validated types are cast in HrDashboardFilters::fromRequest().
 */
class HrProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization handled by Policy in controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'status' => ['nullable', 'string', 'in:pending,in_progress,completed,overdue,archived'],
            'include_archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:updated_at,due_date,status,completed_at'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
