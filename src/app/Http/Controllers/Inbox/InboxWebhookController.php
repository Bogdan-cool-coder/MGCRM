<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundRoutingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\WebhookMessageRequest;
use App\Http\Resources\Inbox\WebhookAckResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic inbound webhook (unauthenticated). Lives in the throttle:inbound
 * group. An external connector POSTs here with the channel's secret in
 * X-Channel-Token.
 *
 * Verification order (E-cases): rate-limit (route middleware) → channel bound by
 * route → token verify (hash_equals; 401 missing / 403 mismatch) → active
 * (503 inactive, E8) → INSERT in try/catch (E1 dedup by external_id) → route to
 * Company+Deal.
 */
class InboxWebhookController extends Controller
{
    public function __construct(
        private readonly InboundRoutingService $router,
    ) {}

    public function webhook(WebhookMessageRequest $request, Channel $channel): JsonResponse
    {
        $provided = $request->header('X-Channel-Token');
        if ($provided === null || $provided === '') {
            return response()->json(['message' => 'Missing X-Channel-Token.'], 401);
        }
        if (! hash_equals($channel->secret_token, $provided)) {
            return response()->json(['message' => 'Invalid X-Channel-Token.'], 403);
        }

        if (! $channel->is_active) {
            return response()->json(['message' => 'Channel is disabled.'], 503);
        }

        $data = $request->validated();
        $externalId = $data['external_id'] ?? null;

        // E1: DB partial-UNIQUE guard against racing duplicate deliveries.
        try {
            $message = InboundMessage::create([
                'channel_id' => $channel->id,
                'external_id' => $externalId,
                'from_identifier' => $data['from_identifier'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'] ?? null,
                'raw_payload' => $data['raw_payload'] ?? null,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            return $this->dedupReplay($channel->id, $externalId);
        }

        // route() self-stamps `failed` and never throws on routing failure, but
        // wrap defensively so an unexpected throwable still returns an idempotent
        // ack rather than a 500 to the external connector (E8).
        try {
            $this->router->route($channel, $message->fresh());
            $fresh = $message->fresh();

            return WebhookAckResource::make([
                'message_id' => $message->id,
                'deal_id' => $fresh->target_deal_id,
                'deal_created' => (bool) $fresh->target_deal_created,
            ])->response()->setStatusCode(201);
        } catch (Throwable $e) {
            Log::error('inbox webhook routing threw', [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'exception' => $e->getMessage(),
            ]);

            return WebhookAckResource::make([
                'message_id' => $message->id,
                'deal_id' => null,
                'deal_created' => false,
            ])->response()->setStatusCode(201);
        }
    }

    /**
     * Idempotent reply on a duplicate delivery: resolve the earlier message for
     * (channel, external_id) and return its routed deal id.
     */
    private function dedupReplay(int $channelId, ?string $externalId): JsonResponse
    {
        $existing = InboundMessage::query()
            ->where('channel_id', $channelId)
            ->where('external_id', $externalId)
            ->orderBy('id')
            ->first();

        return WebhookAckResource::make([
            'message_id' => $existing?->id,
            'deal_id' => $existing?->target_deal_id,
            'deal_created' => false,
        ])->response()->setStatusCode(201);
    }
}
