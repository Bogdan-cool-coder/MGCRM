<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SignDocumentRequest — POST /api/documents/{document}/sign
 *
 * No body required. The guard (signed_scan check) lives in DocumentService.
 */
class SignDocumentRequest extends FormRequest
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
