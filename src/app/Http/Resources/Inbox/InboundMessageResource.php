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
            // raw_payload can hold sender-side internals (headers, webhook envelope).
            // Today only inbox.manage reaches this resource, but gate it so a future
            // permission broadening can't leak the raw envelope to non-triage users.
            'raw_payload' => $this->when((bool) $request->user()?->can('inbox.manage'), $this->raw_payload),
            'target_deal_id' => $this->target_deal_id,
            'target_deal_created' => $this->target_deal_created,
            'routing_status' => $this->routing_status?->value,
            'read_at' => $this->read_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),

            // Embedded so the triage list/detail renders without extra calls.
            // Eager-loaded by the controller (channel, targetDeal.stage) — never N+1.
            'channel' => $this->whenLoaded('channel', fn (): ?array => $this->channel === null ? null : [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'kind' => $this->channel->kind?->value,
            ]),
            'target_deal' => $this->whenLoaded('targetDeal', fn (): ?array => $this->targetDeal === null ? null : [
                'id' => $this->targetDeal->id,
                'title' => $this->targetDeal->title,
                'stage' => $this->targetDeal->relationLoaded('stage') && $this->targetDeal->stage !== null
                    ? [
                        'id' => $this->targetDeal->stage->id,
                        'name' => $this->targetDeal->stage->name,
                    ]
                    : null,
            ]),
        ];
    }
}
