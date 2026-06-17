<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lesson'));
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'in:text,video,pdf,quiz'],
            'content' => ['nullable', 'array'],
            'content.markdown' => ['nullable', 'string', 'max:204800'],
            'content.url' => ['nullable', 'url'],
            'content.provider' => ['nullable', 'string', 'in:youtube,loom,vimeo'],
            'content.path' => ['nullable', 'string', 'max:512'],
            'content.quiz_id' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
