<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInboxDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var InboxDraft|null $draft */
        $draft = $this->route('draft');

        return $draft !== null && $this->user()->can('update', $draft);
    }

    public function rules(): array
    {
        return [
            'related_message_id' => ['sometimes', 'nullable', 'integer', 'exists:inbound_messages,id'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
