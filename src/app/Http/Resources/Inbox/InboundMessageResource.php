<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InboundMessage */
class InboundMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'external_id' => $this->external_id,
            'from_identifier' => $this->from_identifier,
            'from_name' => $this->from_name,
            'subject' => $this->subject,
            'body' => $this->body,
            'raw_payload' => $this->raw_payload,
            'target_deal_id' => $this->target_deal_id,
            'target_deal_created' => $this->target_deal_created,
            'routing_status' => $this->routing_status?->value,
            'received_at' => $this->received_at?->toISOString(),
        ];
    }
}
