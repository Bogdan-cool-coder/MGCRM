<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('form'));
    }

    public function rules(): array
    {
        // slug-conflict is enforced (409) in FormService::update.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'public_slug' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'fields' => ['sometimes', 'array'],
            'fields.*.name' => ['required', 'string', 'max:64'],
            'fields.*.label' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['nullable', 'string', 'max:32'],
            'fields.*.required' => ['nullable', 'boolean'],
            'channel_id' => ['sometimes', 'nullable', 'integer', 'exists:channels,id'],
            'thank_you_text' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
