<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quiz'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'pass_score_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // lesson_id is immutable — silently ignored if sent
        ];
    }
}
