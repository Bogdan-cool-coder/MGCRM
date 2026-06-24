<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingReportQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('question'));
    }

    public function rules(): array
    {
        return [
            'pipeline_id' => ['sometimes', 'nullable', 'integer', 'exists:pipelines,id'],
            'text' => ['sometimes', 'string'],
            'kind' => ['sometimes', 'string', Rule::in(['text', 'select'])],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*.text' => ['required_with:options', 'string', 'max:255'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}
