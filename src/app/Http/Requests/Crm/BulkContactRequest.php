<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate bulk PATCH on contacts (assign_owner / set_tags / add_tag / remove_tag).
 */
class BulkContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Per-entity authorization in BulkContactService::authorizeContacts
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'min:1'],
            'operation' => ['required', 'string', 'in:assign_owner,set_tags,add_tag,remove_tag'],

            // assign_owner
            'owner_id' => ['required_if:operation,assign_owner', 'integer', 'exists:users,id'],

            // set_tags
            'tags' => ['required_if:operation,set_tags', 'array'],
            'tags.*' => ['string', 'max:64'],

            // add_tag / remove_tag
            'tag' => ['required_if:operation,add_tag', 'required_if:operation,remove_tag', 'string', 'max:64'],
        ];
    }
}
