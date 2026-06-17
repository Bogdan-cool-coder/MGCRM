<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Enums\QuestionKind;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', QuizQuestion::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string'],
            'kind' => ['required', Rule::enum(QuestionKind::class)],
            'explanation' => ['nullable', 'string'],
            'points' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
