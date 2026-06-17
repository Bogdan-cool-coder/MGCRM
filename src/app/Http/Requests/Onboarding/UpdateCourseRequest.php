<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('course'));
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cover_image_path' => ['nullable', 'string', 'max:512'],
            'passing_score_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'completion_policy' => ['nullable', 'string', 'in:informational,soft_gate'],
            'deadline_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
