<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Domain\Activity\Models\MeetingReportQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingReportQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MeetingReportQuestion::class);
    }

    public function rules(): array
    {
        return [
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'text' => ['required', 'string'],
            'kind' => ['required', 'string', Rule::in(['text', 'select'])],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.text' => ['required_with:options', 'string', 'max:255'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}
