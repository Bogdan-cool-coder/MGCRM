<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Channel::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(config('inbox.channel_kinds'))],
            'config' => ['nullable', 'array'],
            'default_lead_source' => ['nullable', Rule::in(config('inbox.lead_sources'))],
            'default_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'default_pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'default_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
