<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
