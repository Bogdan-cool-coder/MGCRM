<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Http\FormRequest;

class StorePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Pipeline::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            // S1.5 — sales only (lifecycle/renewal are cs-specialist, S2).
            'kind' => ['nullable', 'in:sales'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
