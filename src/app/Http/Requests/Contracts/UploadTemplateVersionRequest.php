<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UploadTemplateVersionRequest — POST /api/templates/{template}/upload
 *
 * Validates the uploaded docx file. Policy (lawyer/admin) is checked
 * in the controller via authorize(). This FormRequest provides early
 * 422 feedback on file validation failures.
 */
class UploadTemplateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy check is done in the controller via $this->authorize().
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:docx,vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max:20480', // 20 MB
            ],
        ];
    }
}
