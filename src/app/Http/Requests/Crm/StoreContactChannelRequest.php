<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\ChannelType;
use App\Domain\Crm\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Contact $contact */
        $contact = $this->route('contact');

        return $this->user()->can('update', $contact);
    }

    public function rules(): array
    {
        return [
            'channel_type' => ['required', Rule::enum(ChannelType::class)],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:64'],
            'is_primary_for_channel' => ['nullable', 'boolean'],
        ];
    }
}
