<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape for POST /api/message-templates/{id}/preview.
 *
 * $resource is an array: {subject, body, unresolved_keys}
 *
 * @mixin array{subject: string|null, body: string, unresolved_keys: list<string>}
 */
class MessageTemplatePreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'subject' => $this->resource['subject'],
            'body' => $this->resource['body'],
            'unresolved_keys' => $this->resource['unresolved_keys'],
        ];
    }
}
