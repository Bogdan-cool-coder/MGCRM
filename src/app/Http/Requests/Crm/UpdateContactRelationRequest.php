<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\RelationType;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Crm\Policies\ContactRelationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate updating a contact-to-contact relation (PATCH — all fields optional).
 * Authorization checks via ContactRelationPolicy::update.
 */
class UpdateContactRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ContactRelation $relation */
        $relation = $this->route('relation');

        return (new ContactRelationPolicy)->update($this->user(), $relation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'relation_type' => [
                'sometimes',
                Rule::enum(RelationType::class),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
