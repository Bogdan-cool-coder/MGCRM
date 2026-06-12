<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Enums\DocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', Rule::enum(DocumentKind::class)],
            'product_code' => ['required', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2'],
            'title' => ['nullable', 'string', 'max:512'],
            'currency' => ['nullable', 'string', 'max:8'],
            'source_deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'source_company_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'context' => ['nullable', 'array'],
            'extra_fields' => ['nullable', 'array'],
        ];
    }
}
