<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
