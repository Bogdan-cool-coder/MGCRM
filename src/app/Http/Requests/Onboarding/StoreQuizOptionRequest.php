<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Models\QuizOption;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuizOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', QuizOption::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:512'],
            'is_correct' => ['nullable', 'boolean'],
        ];
    }
}
