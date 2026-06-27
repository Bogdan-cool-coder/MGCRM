<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:512'],
            'city' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'max:8'],
            'source_deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'source_company_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'context' => ['nullable', 'array'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'extra_fields' => ['nullable', 'array'],
            // Factual date the physical contract was signed (set by the author after
            // uploading the scan; distinct from the status transition timestamp).
            'signed_at' => ['nullable', 'date'],
        ];
    }
}
