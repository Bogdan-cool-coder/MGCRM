<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_shared' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'payload' => ['required', 'array'],
            'payload.columns' => ['sometimes', 'array'],
            'payload.columns.*' => ['string'],
            'payload.sort' => ['sometimes', 'array'],
            'payload.sort.field' => ['sometimes', 'string'],
            'payload.sort.dir' => ['sometimes', 'string', 'in:asc,desc'],
            'payload.density' => ['sometimes', 'string'],
            'payload.filters' => ['sometimes', 'array'],
        ];
    }
}
