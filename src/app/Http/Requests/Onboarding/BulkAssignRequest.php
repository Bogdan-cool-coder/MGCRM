<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CourseAssignment::class);
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
