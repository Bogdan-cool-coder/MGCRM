<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Enums\QuestionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('question'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['sometimes', 'string'],
            'kind' => ['sometimes', Rule::enum(QuestionKind::class)],
            'explanation' => ['sometimes', 'nullable', 'string'],
            'points' => ['sometimes', 'integer', 'min:1'],
            // HR uses is_draft=false to approve AI-generated draft questions.
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }
}
