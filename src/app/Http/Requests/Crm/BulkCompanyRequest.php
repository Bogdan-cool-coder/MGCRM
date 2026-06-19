<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate bulk PATCH on companies (assign_responsible / set_tags / add_tag / remove_tag).
 */
class BulkCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Per-entity authorization in BulkCompanyService::authorizeCompanies
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'min:1'],
            'operation' => ['required', 'string', 'in:assign_responsible,set_tags,add_tag,remove_tag'],

            // assign_responsible
            'responsible_user_id' => ['required_if:operation,assign_responsible', 'integer', 'exists:users,id'],

            // set_tags
            'tags' => ['required_if:operation,set_tags', 'array'],
            'tags.*' => ['string', 'max:64'],

            // add_tag / remove_tag
            'tag' => ['required_if:operation,add_tag', 'required_if:operation,remove_tag', 'string', 'max:64'],
        ];
    }
}
