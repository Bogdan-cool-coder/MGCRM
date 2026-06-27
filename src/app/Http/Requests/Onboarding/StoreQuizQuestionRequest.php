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
            'text'                   => ['required', 'string'],
            'kind'                   => ['required', Rule::enum(QuestionKind::class)],
            'explanation'            => ['nullable', 'string'],
            'points'                 => ['nullable', 'integer', 'min:1'],
            // Inline options — allows FE to create question + options in one request.
            // Each item: text (required), is_correct (boolean, default false).
            // sort_order is assigned server-side as dense 1..N from array position.
            'options'                => ['sometimes', 'array'],
            'options.*.text'         => ['required', 'string', 'max:512'],
            'options.*.is_correct'   => ['sometimes', 'boolean'],
        ];
    }
}
