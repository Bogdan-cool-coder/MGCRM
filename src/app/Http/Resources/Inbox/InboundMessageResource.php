<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin InboundMessage */
class InboundMessageResource extends JsonResource
{
    /**
     * Max characters of `body` sent in LIST context. The triage list row only
     * renders a single truncated (CSS-ellipsis) line, so a short excerpt is
     * visually identical while cutting the payload — full body is refetched via
     * GET /api/inbox/{id} (show) when a message is opened
     * (Data-Layer-Audit-2026-07 §3.5).
     */
    private const LIST_BODY_EXCERPT = 200;

    /**
     * When true, this resource renders the LEAN list shape: `body` is truncated to
     * an excerpt and `raw_payload` (the webhook envelope, potentially multi-KB) is
     * omitted entirely. The reading pane never uses the list object for body/raw —
     * it refetches the full message via show() — so nothing downstream loses data.
     */
    private bool $listMode = false;

    /**
     * Build a LIST collection (lean rows): truncated body, no raw_payload. Used by
     * the paginated triage index; show()/read()/unread() keep the full resource.
     *
     * @param  iterable<int, InboundMessage>|Paginator<int, InboundMessage>  $resource
     */
    public static function listCollection(mixed $resource): AnonymousResourceCollection
    {
        $collection = self::collection($resource);

        // Flip each wrapped resource into lean list mode. Mapping the underlying
        // ->collection in place keeps the AnonymousResourceCollection (and its
        // pagination meta) intact — self::collection()->map() would unwrap it to a
        // plain Collection and drop the meta envelope.
        $collection->collection->each(static function (self $item): void {
            $item->listMode = true;
        });

        return $collection;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'external_id' => $this->external_id,
            'from_identifier' => $this->from_identifier,
            'from_name' => $this->from_name,
            'subject' => $this->subject,
            // LIST: a ~200-char excerpt (the row shows one ellipsised line anyway);
            // DETAIL: the full body. The key is `body` in both so the FE row binding
            // (msg.body) is unchanged.
            'body' => $this->listMode ? $this->bodyExcerpt() : $this->body,
            // raw_payload can hold sender-side internals (headers, webhook envelope).
            // Today only inbox.manage reaches this resource, but gate it so a future
            // permission broadening can't leak the raw envelope to non-triage users.
            // Never sent in LIST context (the row never reads it; the reading pane
            // refetches the full message via show()).
            $this->mergeWhen(
                ! $this->listMode && (bool) $request->user()?->can('inbox.manage'),
                fn (): array => ['raw_payload' => $this->raw_payload],
            ),
            'target_deal_id' => $this->target_deal_id,
            'target_deal_created' => $this->target_deal_created,
            'routing_status' => $this->routing_status?->value,
            'read_at' => $this->read_at?->toISOString(),
            'starred_at' => $this->starred_at?->toISOString(),
            'important' => (bool) $this->important,
            'snoozed_until' => $this->snoozed_until?->toISOString(),
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

    /**
     * A ~200-char, whitespace-trimmed excerpt of the body for list rows. Returns
     * null when the body is null (the FE `v-if="msg.body"` hides the snippet).
     */
    private function bodyExcerpt(): ?string
    {
        if ($this->body === null) {
            return null;
        }

        return Str::limit(trim((string) $this->body), self::LIST_BODY_EXCERPT);
    }
}
