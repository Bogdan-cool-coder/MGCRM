<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundMessageService;
use App\Domain\Inbox\Services\InboundRoutingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\IndexInboundMessageRequest;
use App\Http\Requests\Inbox\SnoozeInboundMessageRequest;
use App\Http\Resources\Inbox\InboundMessageResource;
use App\Http\Resources\Inbox\InboxCountsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inbox triage (admin/director, inbox.manage). Surfaces the raw inbound log incl.
 * `failed` routing for manual triage, plus the Gmail-style read state, star/
 * important/snooze triage flags (СРЕЗ B), and the «Переобработать» reprocess
 * action. All triage flags are shared on the message (shared mailbox), not
 * per-user — see docs/contracts/inbox-mail-slice-b-contract.md §1.
 *
 * Query-build lives in InboundMessageService (contract §2 refactor debt) — this
 * controller stays thin.
 */
class InboundMessageController extends Controller
{
    public function __construct(private readonly InboundMessageService $service) {}

    public function index(IndexInboundMessageRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InboundMessage::class);

        return InboundMessageResource::collection($this->service->paginate($request->validated()));
    }

    public function show(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('view', $inboundMessage);

        // Detail must NOT auto-mark read — the FE calls POST .../read on open.
        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * Mark a message read (Gmail-style). Idempotent: sets read_at = now only when
     * it is currently null, so re-calling never moves the timestamp.
     */
    public function read(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        if ($inboundMessage->read_at === null) {
            $inboundMessage->forceFill(['read_at' => now()])->save();
        }

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * Mark a message unread. Idempotent: clears read_at back to null.
     */
    public function unread(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        if ($inboundMessage->read_at !== null) {
            $inboundMessage->forceFill(['read_at' => null])->save();
        }

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * Sidebar unread badge: count of unread messages within the inbox.manage
     * scope (the whole shared log — read state is not per-user). Snooze-aware
     * (contract §4.5.1) — shares the exact formula with counts.folders.inbox_unread.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InboundMessage::class);

        return response()->json(['count' => $this->service->inboxUnreadCount()]);
    }

    /**
     * POST .../star — idempotent: sets starred_at = now() only if currently null.
     */
    public function star(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        $this->service->star($inboundMessage);

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * DELETE .../star — idempotent: clears starred_at back to null.
     */
    public function unstar(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        $this->service->unstar($inboundMessage);

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * POST .../important — idempotent: sets important = true.
     */
    public function markImportant(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        $this->service->markImportant($inboundMessage);

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * DELETE .../important — idempotent: sets important = false.
     */
    public function unmarkImportant(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        $this->service->unmarkImportant($inboundMessage);

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * POST .../snooze — sets snoozed_until to the validated future instant.
     */
    public function snooze(SnoozeInboundMessageRequest $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->service->snooze($inboundMessage, $request->date('until'));

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * DELETE .../snooze — manual early return: clears snoozed_until back to null.
     */
    public function unsnooze(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        $this->service->unsnooze($inboundMessage);

        return InboundMessageResource::make($inboundMessage->load($this->service->triageRelations()));
    }

    /**
     * GET /api/inbox/counts — folder + per-channel unread aggregates (contract
     * §4.5). Gated the same as the list (viewAny / inbox.manage).
     */
    public function counts(Request $request): InboxCountsResource
    {
        $this->authorize('viewAny', InboundMessage::class);

        return new InboxCountsResource($this->service->counts((int) $request->user()->id));
    }

    /**
     * «Переобработать» — re-run routing on a message (primarily a `failed` one).
     * Re-resolves pipeline/stage and dedups-or-creates Company + Deal via
     * InboundRoutingService::route(). external_id dedup is respected (a re-route
     * of an already-routed id links rather than duplicates). When no pipeline can
     * be resolved the message stays `failed` — never a 500.
     */
    public function reroute(Request $request, InboundMessage $inboundMessage, InboundRoutingService $routing): JsonResource
    {
        $this->authorize('manage', $inboundMessage);

        // Idempotency: a message already routed to a live deal is left untouched —
        // re-running route() on it would mint a SECOND deal (the cross-row
        // external_id dedup can't see the message's own deal, and the DB
        // partial-unique index blocks a sibling row from carrying the dedup
        // pointer). Reprocess is for `failed`/`dedup` triage, so we skip when the
        // message already has its deal and link to it instead of duplicating.
        $alreadyRouted = $inboundMessage->routing_status === RoutingStatus::Routed
            && $inboundMessage->target_deal_id !== null;

        if (! $alreadyRouted) {
            $channel = $inboundMessage->channel()->first();

            // The channel FK is cascadeOnDelete, so a persisted message always has
            // a channel; guard defensively rather than assume.
            if ($channel !== null) {
                $routing->route($channel, $inboundMessage);
            }
        }

        return InboundMessageResource::make($inboundMessage->fresh()?->load($this->service->triageRelations()) ?? $inboundMessage);
    }
}
