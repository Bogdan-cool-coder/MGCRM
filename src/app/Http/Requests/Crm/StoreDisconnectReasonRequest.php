<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisconnectReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced via $this->authorize('admin-write') in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
