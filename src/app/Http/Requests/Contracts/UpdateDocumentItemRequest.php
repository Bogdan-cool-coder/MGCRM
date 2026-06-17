<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'qty' => ['nullable', 'numeric', 'min:0.001', 'max:99999.999'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
