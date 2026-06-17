<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WebhookAckResource — the generic webhook acknowledgement. Wraps a plain array
 * {message_id, deal_id, deal_created}.
 */
class WebhookAckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->resource;

        return [
            'message_id' => $r['message_id'],
            'deal_id' => $r['deal_id'] ?? null,
            'deal_created' => $r['deal_created'] ?? false,
        ];
    }
}
