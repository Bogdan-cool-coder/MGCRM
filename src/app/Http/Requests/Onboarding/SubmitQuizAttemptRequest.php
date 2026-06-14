<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership verified in service
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'min:1'],
            'answers.*.selected_option_ids' => ['required', 'array'],
            'answers.*.selected_option_ids.*' => ['integer', 'min:1'],
        ];
    }
}
