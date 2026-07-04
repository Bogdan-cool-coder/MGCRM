<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InboxDraft */
class InboxDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'related_message_id' => $this->related_message_id,
            'subject' => $this->subject,
            'body' => $this->body,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Optional short meta of the related message, when eager-loaded.
            'related_message' => $this->whenLoaded('relatedMessage', fn (): ?array => $this->relatedMessage === null ? null : [
                'id' => $this->relatedMessage->id,
                'from_name' => $this->relatedMessage->from_name,
                'subject' => $this->relatedMessage->subject,
            ]),
        ];
    }
}
