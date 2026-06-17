<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Lesson::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'in:text,video,pdf,quiz'],
            'content' => ['required', 'array'],
            // text
            'content.markdown' => ['required_if:kind,text', 'nullable', 'string', 'max:204800'],
            // video
            'content.url' => ['required_if:kind,video', 'nullable', 'url'],
            'content.provider' => ['required_if:kind,video', 'nullable', 'string', 'in:youtube,loom,vimeo'],
            // pdf
            'content.path' => ['nullable', 'string', 'max:512'],
            // quiz
            'content.quiz_id' => ['nullable', 'integer'],
            // common
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
