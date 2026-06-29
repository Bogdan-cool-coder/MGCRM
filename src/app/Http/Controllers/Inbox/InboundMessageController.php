<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundRoutingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inbox\InboundMessageResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Inbox triage (admin/director, inbox.manage). Surfaces the raw inbound log incl.
 * `failed` routing for manual triage, plus the Gmail-style read state and the
 * «Переобработать» reprocess action. Read state lives on the message (shared
 * mailbox), not per-user.
 */
class InboundMessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InboundMessage::class);

        // Validate the date bounds up front: they are fed straight into
        // Carbon::parse for the Dubai-tz day math, so a malformed value must 422
        // (not 500). Other filters are typed/escaped at use, so they need no rule.
        $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
        ]);

        $messages = InboundMessage::query()
            ->with($this->triageRelations())
            ->when($request->filled('channel_id'), fn (Builder $q) => $q->where('channel_id', $request->integer('channel_id')))
            ->when($request->filled('routing_status'), fn (Builder $q) => $q->where('routing_status', $request->query('routing_status')))
            // q → substring across sender + subject + body (the triage search box).
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$this->escapeLike(trim((string) $request->query('q'))).'%';
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('from_identifier', 'like', $term)
                        ->orWhere('from_name', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('body', 'like', $term);
                });
            })
            // has_deal → routed-to-a-deal vs not (true = has a target_deal_id).
            ->when($request->filled('has_deal'), function (Builder $q) use ($request): void {
                $request->boolean('has_deal')
                    ? $q->whereNotNull('target_deal_id')
                    : $q->whereNull('target_deal_id');
            })
            // unread → read_at IS NULL (true) / IS NOT NULL (false).
            ->when($request->filled('unread'), function (Builder $q) use ($request): void {
                $request->boolean('unread')
                    ? $q->whereNull('read_at')
                    : $q->whereNotNull('read_at');
            })
            // date_from / date_to → received_at range, day bounds in the operational
            // timezone (Дубай-окно) so the calendar day matches the rest of the app.
            ->when($request->filled('date_from'), fn (Builder $q) => $q->where('received_at', '>=', $this->operationalDayStart((string) $request->query('date_from'))))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->where('received_at', '<=', $this->operationalDayEnd((string) $request->query('date_to'))))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            // Clamp per_page so a hostile/absurd value can't pull the whole table
            // into one page (#15). Mirrors DocumentController's min(..., 100) cap.
            ->paginate(min((int) $request->query('per_page', 50), 100))
            ->withQueryString();

        return InboundMessageResource::collection($messages);
    }

    public function show(Request $request, InboundMessage $inboundMessage): JsonResource
    {
        $this->authorize('view', $inboundMessage);

        // Detail must NOT auto-mark read — the FE calls POST .../read on open.
        return InboundMessageResource::make($inboundMessage->load($this->triageRelations()));
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

        return InboundMessageResource::make($inboundMessage->load($this->triageRelations()));
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

        return InboundMessageResource::make($inboundMessage->load($this->triageRelations()));
    }

    /**
     * Sidebar unread badge: count of unread messages within the inbox.manage
     * scope (the whole shared log — read state is not per-user).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InboundMessage::class);

        return response()->json([
            'count' => InboundMessage::query()->whereNull('read_at')->count(),
        ]);
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

        return InboundMessageResource::make($inboundMessage->fresh()?->load($this->triageRelations()) ?? $inboundMessage);
    }

    /**
     * Eager-load set for the triage resource: channel + the routed deal with its
     * stage. Batched (no N+1) and shared across index/show/read/unread/reroute.
     *
     * @return array<int|string, mixed>
     */
    private function triageRelations(): array
    {
        return ['channel', 'targetDeal.stage'];
    }

    /**
     * Start-of-day for a YYYY-MM-DD filter bound, in the operational timezone,
     * returned as a UTC instant to compare against the UTC-stored received_at.
     */
    private function operationalDayStart(string $date): Carbon
    {
        return Carbon::parse($date, $this->operationalTimezone())->startOfDay()->utc();
    }

    /** End-of-day (inclusive) for a YYYY-MM-DD filter bound, operational tz → UTC. */
    private function operationalDayEnd(string $date): Carbon
    {
        return Carbon::parse($date, $this->operationalTimezone())->endOfDay()->utc();
    }

    /**
     * The operational timezone (Дубай-окно) — the single source already used by
     * the SalesPulse / Activity day-window math, so the Inbox date filter never
     * drifts from the rest of the app.
     */
    private function operationalTimezone(): string
    {
        /** @var string $tz */
        $tz = config('salespulse.timezone', 'Asia/Dubai');

        return $tz;
    }

    /** Escape LIKE wildcards in a user search term (default backslash escape). */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
