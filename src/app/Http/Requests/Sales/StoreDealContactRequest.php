<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreDealContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('deal'));
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:crm_contacts,id'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
