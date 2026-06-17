<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FormPublicResource — anon-safe view returned by GET /forms/public/{slug}.
 * No slug/channel/internal ids — the anonymous renderer sees only what it needs.
 *
 * Wraps the plain array produced by FormService::publicMeta().
 */
class FormPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $meta */
        $meta = $this->resource;

        return [
            'name' => $meta['name'],
            'fields' => $meta['fields'],
            'thank_you_text' => $meta['thank_you_text'],
        ];
    }
}
