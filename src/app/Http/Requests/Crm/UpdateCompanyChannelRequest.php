<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\ChannelType;
use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        return $this->user()->can('update', $company);
    }

    public function rules(): array
    {
        return [
            'channel_type' => ['sometimes', Rule::enum(ChannelType::class)],
            'value' => ['sometimes', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:64'],
            'is_primary_for_channel' => ['nullable', 'boolean'],
        ];
    }
}
