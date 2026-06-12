<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('channel'));
    }

    public function rules(): array
    {
        // secret_token is intentionally NOT accepted here (regenerate only).
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(config('inbox.channel_kinds'))],
            'config' => ['sometimes', 'nullable', 'array'],
            'default_lead_source' => ['sometimes', 'nullable', Rule::in(config('inbox.lead_sources'))],
            'default_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'default_pipeline_id' => ['sometimes', 'nullable', 'integer', 'exists:pipelines,id'],
            'default_stage_id' => ['sometimes', 'nullable', 'integer', 'exists:pipeline_stages,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
