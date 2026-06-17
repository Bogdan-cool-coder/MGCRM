<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:512'],
        ];
    }
}
