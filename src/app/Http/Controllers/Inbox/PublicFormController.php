<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\FormService;
use App\Domain\Inbox\Services\FormSubmissionValidator;
use App\Domain\Inbox\Services\InboundRoutingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inbox\FormPublicResource;
use App\Http\Resources\Inbox\FormSubmitResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public (unauthenticated) form endpoints. Lives in the throttle:inbound group.
 *
 * Flow (E-cases from the S1.9 plan):
 *   meta   — anon-safe form metadata; 404 for inactive (hides existence).
 *   submit — rate-limit (E5, route middleware) → form/active (E8) → honeypot
 *            (E4) → strict validation (E7) → channel resolve (E8) → build
 *            message + stable external_id (E6) → INSERT in try/catch (E1 dedup)
 *            → route to Company+Deal → response.
 */
class PublicFormController extends Controller
{
    public function __construct(
        private readonly FormService $forms,
        private readonly FormSubmissionValidator $validator,
        private readonly InboundRoutingService $router,
    ) {}

    public function meta(Request $request, string $slug): JsonResource
    {
        $form = Form::query()->where('public_slug', $slug)->firstOrFail();

        // publicMeta throws ModelNotFoundException (→ 404) for inactive forms.
        return FormPublicResource::make($this->forms->publicMeta($form));
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        $form = Form::query()->where('public_slug', $slug)->firstOrFail();
        if (! $form->is_active) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        /** @var array<string, mixed> $submission */
        $submission = $request->all();

        // E4: honeypot filled → silent OK (don't reveal the mechanism), no Deal.
        if ($this->validator->isHoneypotFilled($submission)) {
            return $this->ok($form, dealCreated: false, dealId: null);
        }

        // E7: strict validation by form.fields.
        $result = $this->validator->validate($form->fields ?? [], $submission);
        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 400);
        }

        // E8: no channel / inactive channel → accept, but create no Deal.
        $channel = $form->channel_id !== null ? Channel::find($form->channel_id) : null;
        if ($channel === null || ! $channel->is_active) {
            return $this->ok($form, dealCreated: false, dealId: null);
        }

        // E6: stable external_id (double-click / refresh dedup). Null when no contact.
        $externalId = $this->validator->externalId($slug, $submission, microtime(true));
        $messageFields = $this->validator->buildMessageFields($form->name, $submission);

        // E1: DB partial-UNIQUE (channel_id, external_id) is the final guard
        // against a racing duplicate. Catch the integrity violation and respond
        // idempotently with the already-routed deal.
        try {
            $message = InboundMessage::create([
                'channel_id' => $channel->id,
                'external_id' => $externalId,
                'raw_payload' => $submission,
                'received_at' => now(),
                ...$messageFields,
            ]);
        } catch (QueryException $e) {
            return $this->dedupReplay($form, $channel->id, $externalId);
        }

        $deal = $this->router->route($channel, $message->fresh());

        return $this->ok(
            $form,
            dealCreated: (bool) ($deal !== null && $message->fresh()->target_deal_created),
            dealId: $deal?->id,
        );
    }

    /**
     * Idempotent reply on a duplicate submit: resolve the earlier message for
     * (channel, external_id) and return its routed deal id.
     */
    private function dedupReplay(Form $form, int $channelId, ?string $externalId): JsonResponse
    {
        $existing = InboundMessage::query()
            ->where('channel_id', $channelId)
            ->where('external_id', $externalId)
            ->orderBy('id')
            ->first();

        return $this->ok(
            $form,
            dealCreated: false,
            dealId: $existing?->target_deal_id,
        );
    }

    private function ok(Form $form, bool $dealCreated, ?int $dealId): JsonResponse
    {
        return FormSubmitResource::make([
            'ok' => true,
            'thank_you_text' => $form->thank_you_text,
            'deal_created' => $dealCreated,
            'deal_id' => $dealId,
        ])->response()->setStatusCode(201);
    }
}
