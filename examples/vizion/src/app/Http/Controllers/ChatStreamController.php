<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP surface for the streaming AI-chat event log (M5 + M6).
 *
 * Two endpoints, one source of truth (`chat_message_events`):
 *
 *  - stream()  GET /api/chats/{chat}/stream/{message}
 *      Server-Sent Events (SSE) stream for an in-flight assistant turn. The
 *      frontend subscribes via EventSource and receives one SSE frame per
 *      ChatMessageEvent row, in `sequence` order. Catch-up is automatic:
 *      events that already exist when the stream opens are flushed first,
 *      then we tail-poll for new rows until the message reaches a terminal
 *      status (done / error / cancelled).
 *
 *  - events() GET /api/chats/{chat}/messages/{message}/events
 *      Plain JSON batch reader. Used by the frontend to render the event
 *      timeline of an already-finished assistant message after a reload
 *      (when there is no active job and no point in subscribing to SSE).
 *
 * Shared concerns (auth scoping, message-belongs-to-chat check,
 * assistant-only enforcement) live in resolveStreamableMessage(). Both
 * endpoints return 404 — not 403 — for "message exists but does not belong
 * to this chat" to avoid leaking cross-chat ID enumeration.
 */
class ChatStreamController extends Controller
{
    /**
     * Wall-clock budget for a single SSE connection, in seconds. After this we
     * close the stream gracefully — the browser (EventSource) reconnects with
     * a Last-Event-ID header and we resume from where we left off. Matches
     * the nginx `proxy_read_timeout 480s` upstream cap so we always close
     * cleanly before the proxy kills us with a 504.
     */
    private const STREAM_BUDGET_SECONDS = 480;

    /**
     * Poll interval for the tail-loop. 300 ms keeps perceived latency well
     * under "feels instant" while bounding the per-connection DB load to
     * ~3 queries/sec — negligible against the small chat_message_events
     * table.
     */
    private const STREAM_POLL_INTERVAL_MICROSECONDS = 300_000;

    /**
     * Hard cap on rows fetched per inner loop iteration. Without this a long
     * burst of buffered events could keep us in the SQL phase indefinitely
     * and starve the connection_aborted() / status-refresh checks.
     */
    private const STREAM_BATCH_LIMIT = 100;

    /**
     * Default page size for the batch events endpoint. Picked to comfortably
     * carry a full long turn (~50-80 events typical) in one round-trip
     * while staying small enough that the JSON serialisation stays fast.
     */
    private const EVENTS_PAGE_SIZE_DEFAULT = 100;

    /**
     * Hard ceiling on ?limit= for the batch endpoint. Stops a misbehaving
     * client from asking for 100k events in a single response.
     */
    private const EVENTS_PAGE_SIZE_MAX = 500;

    /**
     * Open an SSE stream for a single assistant ChatMessage.
     *
     * Resume semantics:
     *   - Explicit `?since=N` query parameter wins (an explicit caller intent
     *     should always override implicit headers).
     *   - Otherwise we honour `Last-Event-ID`, which browsers set automatically
     *     on EventSource reconnect.
     *   - Otherwise we replay from sequence 1 (i.e. send every event ever
     *     written for the message). This makes the endpoint usable as both
     *     a live stream AND a replay on cold subscribe.
     *
     * Termination:
     *   - The loop emits an `event: done` sentinel and returns when the
     *     parent message reaches a terminal status (done / error / cancelled)
     *     AND there are no more buffered events past $lastSent.
     *   - On wall-clock budget exhaustion we just `return` without a sentinel
     *     — the browser will reconnect and resume.
     *   - On client disconnect (`connection_aborted()`) we return immediately.
     */
    public function stream(Request $request, Chat $chat, ChatMessage $message): Response
    {
        $access = $this->resolveStreamableMessage($request, $chat, $message);

        if ($access instanceof JsonResponse) {
            // Auth / validation rejection — short-circuit with a normal JSON
            // response. We declare the method return type as the framework
            // Response superclass so both JsonResponse and StreamedResponse
            // are valid returns.
            return $access;
        }

        // since=N takes precedence over the Last-Event-ID header — explicit
        // caller intent beats implicit EventSource auto-reconnect.
        $since = $this->resolveSinceCursor($request);

        return new StreamedResponse(
            function () use ($message, $since): void {
                $this->runStreamLoop($message, $since);
            },
            200,
            [
                'Content-Type'      => 'text/event-stream; charset=UTF-8',
                'Cache-Control'     => 'no-cache, must-revalidate',
                // Tell nginx (and any other proxy that respects it) to NOT
                // buffer the response — otherwise the user wouldn't see
                // anything until the connection closed.
                'X-Accel-Buffering' => 'no',
                'Connection'        => 'keep-alive',
            ]
        );
    }

    /**
     * Return a paginated batch of events for an assistant ChatMessage.
     *
     * Cursor semantics use `?since=N` for parity with the SSE endpoint —
     * the frontend can reuse the same "last seen sequence" bookkeeping for
     * both live-streamed and reload-restored timelines.
     */
    public function events(Request $request, Chat $chat, ChatMessage $message): JsonResponse
    {
        $access = $this->resolveStreamableMessage($request, $chat, $message);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $since = max(0, (int) $request->query('since', 0));
        $limit = (int) $request->query('limit', self::EVENTS_PAGE_SIZE_DEFAULT);
        $limit = max(1, min(self::EVENTS_PAGE_SIZE_MAX, $limit));

        // Fetch one extra row so we can tell whether more pages remain
        // without a second COUNT query.
        $rows = ChatMessageEvent::query()
            ->where('chat_message_id', $message->id)
            ->where('sequence', '>', $since)
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit);
        }

        $events = $rows->map(fn (ChatMessageEvent $event) => [
            'sequence'   => $event->sequence,
            'type'       => $event->type,
            'payload'    => $event->payload,
            'created_at' => $event->created_at?->toIso8601String(),
        ])->values();

        $nextCursor = $hasMore ? $rows->last()?->sequence : null;

        return response()->json([
            'events'         => $events,
            'message_status' => $message->status,
            'has_more'       => $hasMore,
            'next_cursor'    => $nextCursor,
        ]);
    }

    /**
     * Inner SSE loop. Split out from stream() so it can stay tight and
     * single-purpose; testability is the secondary win (the loop terminates
     * deterministically once the message is terminal and the buffer is
     * drained — no async callbacks needed in tests).
     */
    private function runStreamLoop(ChatMessage $message, int $since): void
    {
        $startedAt = time();
        $lastSent  = $since;

        while (true) {
            // 1. Drain any buffered events past $lastSent.
            $events = ChatMessageEvent::query()
                ->where('chat_message_id', $message->id)
                ->where('sequence', '>', $lastSent)
                ->orderBy('sequence')
                ->limit(self::STREAM_BATCH_LIMIT)
                ->get();

            foreach ($events as $event) {
                $this->writeSseEvent(
                    id: $event->sequence,
                    event: $event->type,
                    data: [
                        'type'       => $event->type,
                        'sequence'   => $event->sequence,
                        'payload'    => $event->payload,
                        'created_at' => $event->created_at?->toIso8601String(),
                    ],
                );
                $lastSent = $event->sequence;

                // Check after each flush — lets us bail fast on client
                // disconnect during a long catch-up burst.
                if ($this->isClientGone()) {
                    return;
                }
            }

            // 2. If there might be MORE buffered events past this batch
            //    (we hit STREAM_BATCH_LIMIT), keep draining without delay.
            if ($events->count() === self::STREAM_BATCH_LIMIT) {
                continue;
            }

            // 3. Refresh the message status — the job may have flipped to
            //    done/error/cancelled in the gap between query #1 and now.
            //    We deliberately re-read instead of using $message->refresh()
            //    on a stale model to keep the query explicit.
            $status = ChatMessage::query()
                ->whereKey($message->id)
                ->value('status');

            if ($status !== null && $this->isTerminalStatus((string) $status)) {
                // Race window: an event for $lastSent+1 may have been written
                // AFTER our SELECT above but BEFORE the status flipped. Do one
                // more drain pass before sending the sentinel.
                $tail = ChatMessageEvent::query()
                    ->where('chat_message_id', $message->id)
                    ->where('sequence', '>', $lastSent)
                    ->orderBy('sequence')
                    ->get();

                foreach ($tail as $event) {
                    $this->writeSseEvent(
                        id: $event->sequence,
                        event: $event->type,
                        data: [
                            'type'       => $event->type,
                            'sequence'   => $event->sequence,
                            'payload'    => $event->payload,
                            'created_at' => $event->created_at?->toIso8601String(),
                        ],
                    );
                    $lastSent = $event->sequence;
                }

                $this->writeSseEvent(
                    id: null,
                    event: 'done',
                    data: ['status' => $status],
                );
                return;
            }

            // 4. Budget check — close gracefully and let EventSource reconnect.
            if (time() - $startedAt >= self::STREAM_BUDGET_SECONDS) {
                return;
            }

            if ($this->isClientGone()) {
                return;
            }

            // 5. Throttle the next poll. usleep is interruptible by SIGTERM
            //    when running under php-fpm, so the worker stays responsive
            //    to graceful shutdown signals.
            usleep(self::STREAM_POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * Validate that the caller can stream events from $message inside $chat,
     * and that $message is an assistant message (events are only meaningful
     * for assistant turns — user messages have nothing to stream).
     *
     * Returns null on success, or a JsonResponse with the appropriate error
     * code (404 / 403 / 422) for the caller to short-circuit on.
     *
     * 404 vs 403: we use 404 specifically for "message does not belong to
     * this chat". Returning 403 there would leak the existence of valid
     * message IDs across chats; 404 is the standard "hide cross-resource
     * enumeration" response.
     */
    private function resolveStreamableMessage(Request $request, Chat $chat, ChatMessage $message): ?JsonResponse
    {
        if ((int) $message->chat_id !== (int) $chat->id) {
            return response()->json(['message' => __('chats.message_not_found')], 404);
        }

        if (!$this->canAccessChat($request, $chat)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if ($message->role !== 'assistant') {
            // Streaming a user message is a category error — there are no
            // events for it. 422 (unprocessable entity) communicates "the
            // request shape is valid but the target is not stream-able".
            return response()->json([
                'message' => __('chats.only_assistant_messages_can_be_streamed'),
                'code'    => 'not_streamable',
            ], 422);
        }

        return null;
    }

    /**
     * Re-implementation of ChatController::canAccessChat. We don't import
     * from there because (a) PHP traits would be the only clean way and the
     * helper is genuinely small, and (b) the auth contract for streaming
     * may diverge from CRUD in the future (e.g. read-only role for
     * embedded MACRO iframes).
     */
    private function canAccessChat(Request $request, Chat $chat): bool
    {
        $user            = $request->user();
        $activeCompanyId = (int) ($request->attributes->get('active_company_id') ?? $user->company_id);

        if ((int) $chat->company_id !== $activeCompanyId) {
            return false;
        }

        if (!$user->canAccessCompany((int) $chat->company_id)) {
            return false;
        }

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        return (int) $chat->user_id === (int) $user->id;
    }

    /**
     * Resolve the resume cursor from the request. Explicit ?since= wins over
     * the Last-Event-ID header — explicit always beats implicit. Both are
     * clamped to >= 0 so a malformed value doesn't blow up the SQL `>`
     * comparison.
     */
    private function resolveSinceCursor(Request $request): int
    {
        $raw = $request->query('since');

        if ($raw !== null && $raw !== '') {
            return max(0, (int) $raw);
        }

        $header = $request->header('Last-Event-ID');
        if ($header !== null && $header !== '') {
            return max(0, (int) $header);
        }

        return 0;
    }

    /**
     * Statuses that mean "no further events will be emitted for this
     * message". cancelled is included alongside done/error so a cancelled
     * turn closes the stream cleanly instead of dangling until the
     * wall-clock budget expires.
     */
    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            ChatMessage::STATUS_DONE,
            ChatMessage::STATUS_ERROR,
            ChatMessage::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Emit one SSE frame. The format is:
     *   id: <sequence>            (optional — omitted for the `done` sentinel)
     *   event: <type>
     *   data: <json>
     *   \n
     *
     * Output buffering is flushed AFTER each frame so the client sees events
     * in real time instead of in one lump at connection close. We check
     * ob_get_level() because nginx + php-fpm sometimes runs without an
     * active output buffer (the user-space `output_buffering` ini setting)
     * and ob_flush() would warn in that case.
     */
    private function writeSseEvent(?int $id, string $event, array $data): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

        // Flush every layer of buffering we might be behind: user-space ob_*
        // (only if active), then the SAPI-level buffer via flush(). Order
        // matters — ob_flush() before flush().
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Detect that the HTTP client closed the connection. Wrapped for
     * testability — the test environment can stub this to false to keep the
     * loop predictable.
     */
    private function isClientGone(): bool
    {
        return connection_aborted() === 1;
    }
}
