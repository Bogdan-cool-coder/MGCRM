<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('pipeline'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'settings' => ['sometimes', 'array'],
            // kind is immutable after creation — changing it breaks funnel semantics.
            'kind' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.prohibited' => 'Pipeline kind cannot be changed after creation.',
        ];
    }
}
