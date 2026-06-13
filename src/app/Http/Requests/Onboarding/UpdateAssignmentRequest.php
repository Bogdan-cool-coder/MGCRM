<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CourseAssignment $assignment */
        $assignment = $this->route('assignment');

        return $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date', 'after:today'],
            'status' => ['nullable', 'string', 'in:archived'],
        ];
    }
}
