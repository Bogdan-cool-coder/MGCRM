<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class CompleteLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership verified in service
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
