<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FormSubmitResource — the public submit response. Wraps a plain array
 * {ok, thank_you_text, deal_created, deal_id}. The clean MGCRM contract carries
 * no lead_* backward-compat aliases (unlike the legacy FastAPI response).
 */
class FormSubmitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->resource;

        return [
            'ok' => $r['ok'],
            'thank_you_text' => $r['thank_you_text'] ?? null,
            'deal_created' => $r['deal_created'] ?? false,
            'deal_id' => $r['deal_id'] ?? null,
        ];
    }
}
