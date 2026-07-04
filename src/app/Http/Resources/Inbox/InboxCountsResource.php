<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InboxCountsResource — GET /api/inbox/counts (contract §4.5). Wraps the
 * array returned by InboundMessageService::counts(). Default `$wrap = 'data'`
 * so the response is `{"data": {"folders": {...}, "channels": {...}}}`, matching
 * the contract's example shape.
 */
class InboxCountsResource extends JsonResource
{
    /**
     * @param  array{folders: array<string, int>, channels: array<string, int>}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{folders: array<string, int>, channels: array<string, int>} $data */
        $data = $this->resource;

        return $data;
    }
}
