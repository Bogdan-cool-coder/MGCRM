<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\InboundMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inbox\InboundMessageResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only Inbox list (admin/director). Surfaces the raw inbound log incl.
 * `failed` routing for manual triage — the Inbox UI seed (S1.9 is backend-only).
 */
class InboundMessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InboundMessage::class);

        $messages = InboundMessage::query()
            ->when($request->filled('channel_id'), fn (Builder $q) => $q->where('channel_id', $request->integer('channel_id')))
            ->when($request->filled('routing_status'), fn (Builder $q) => $q->where('routing_status', $request->query('routing_status')))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 50));

        return InboundMessageResource::collection($messages);
    }

    public function show(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('view', $inboundMessage);

        return InboundMessageResource::make($inboundMessage);
    }
}
