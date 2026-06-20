<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\ContactStatus;
use App\Domain\Crm\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Contact::class);
    }

    public function rules(): array
    {
        return [
            'full_name'              => ['required', 'string', 'max:255'],
            'position'               => ['nullable', 'string', 'max:128'],
            'phone'                  => ['nullable', 'string', 'max:64'],
            'email'                  => ['nullable', 'email', 'max:255'],
            'tg_username'            => ['nullable', 'string', 'max:64'],
            'notes'                  => ['nullable', 'string'],
            'source'                 => ['nullable', 'string', 'max:32'],
            'acquisition_channel_id' => ['nullable', 'integer', 'exists:acquisition_channels,id'],
            'status'                 => ['nullable', Rule::enum(ContactStatus::class)],
            'tags'                   => ['nullable', 'array'],
            'tags.*'                 => ['string', 'max:64'],
            'extra_fields'           => ['nullable', 'array'],
            'owner_id'               => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
