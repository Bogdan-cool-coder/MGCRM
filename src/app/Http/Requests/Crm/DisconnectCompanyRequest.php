<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for POST /companies/{company}/disconnect.
 */
class DisconnectCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy enforced via gate in controller
    }

    public function rules(): array
    {
        return [
            'disconnect_reason_id' => ['required', 'integer', 'exists:disconnect_reasons,id'],
            // N6 will enforce that a signed scan must be attached before calling disconnect;
            // for now docId is optional so the service method can be called without it.
            'disconnect_doc_id' => ['nullable', 'integer'],
        ];
    }
}
