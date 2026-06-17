<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreDocumentRemarkRequest — POST /api/documents/{document}/remarks
 */
class StoreDocumentRemarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check is done in the controller via authorize().
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
