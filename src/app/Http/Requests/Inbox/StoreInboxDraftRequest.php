<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Foundation\Http\FormRequest;

class StoreInboxDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InboxDraft::class);
    }

    public function rules(): array
    {
        return [
            'related_message_id' => ['nullable', 'integer', 'exists:inbound_messages,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ];
    }
}
