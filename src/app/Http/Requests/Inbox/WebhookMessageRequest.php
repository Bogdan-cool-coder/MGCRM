<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of the generic inbound webhook. Unauthenticated — token verification
 * (X-Channel-Token) and the active-channel check happen in the controller, NOT
 * here (a FormRequest cannot read the route-bound channel's secret safely).
 * Every field is optional (it depends on the channel/connector).
 */
class WebhookMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:128'],
            'from_identifier' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'raw_payload' => ['nullable', 'array'],
        ];
    }
}
