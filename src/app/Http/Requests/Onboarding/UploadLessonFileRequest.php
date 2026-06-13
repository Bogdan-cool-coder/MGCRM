<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UploadLessonFileRequest — validates a PDF upload for kind=pdf lessons.
 * Max 50 MB. Stores via LessonService::storeFile().
 */
class UploadLessonFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lesson'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ];
    }
}
