<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\RelationType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Policies\ContactRelationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate creating a contact-to-contact relation.
 * Authorization checks via ContactRelationPolicy::create.
 */
class StoreContactRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Contact $contact */
        $contact = $this->route('contact');

        return (new ContactRelationPolicy)->create($this->user(), $contact);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Contact $contact */
        $contact = $this->route('contact');

        return [
            'related_contact_id' => [
                'required',
                'integer',
                'exists:crm_contacts,id',
                Rule::notIn([$contact->id]),
            ],
            'relation_type' => [
                'required',
                Rule::enum(RelationType::class),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'related_contact_id.not_in' => 'A contact cannot have a relation with itself.',
        ];
    }
}
