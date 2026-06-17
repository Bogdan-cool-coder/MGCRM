<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ReorderRequest — shared for both module reorder and lesson reorder.
 * Accepts an ordered array of {id} objects. Array position = new sort_order.
 */
class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy check happens in the controller via $this->authorize().
        return true;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => ['required', 'integer'],
        ];
    }
}
