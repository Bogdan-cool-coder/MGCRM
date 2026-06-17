<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DismissDedupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(['contact', 'company'])],
            'entity_a_id' => ['required', 'integer', 'min:1'],
            'entity_b_id' => ['required', 'integer', 'min:1', 'different:entity_a_id'],
        ];
    }
}
