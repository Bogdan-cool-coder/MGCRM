<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UnsignDocumentRequest — POST /api/documents/{document}/unsign
 *
 * No body required. Admin/lawyer only (enforced by DocumentPolicy::unsign).
 */
class UnsignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check is done in the controller via authorize().
    }

    public function rules(): array
    {
        return [];
    }
}
