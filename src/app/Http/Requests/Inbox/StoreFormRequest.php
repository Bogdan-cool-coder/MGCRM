<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\Form;
use Illuminate\Foundation\Http\FormRequest;

class StoreFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Form::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'public_slug' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'fields' => ['nullable', 'array'],
            'fields.*.name' => ['required', 'string', 'max:64'],
            'fields.*.label' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['nullable', 'string', 'max:32'],
            'fields.*.required' => ['nullable', 'boolean'],
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],
            'thank_you_text' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
