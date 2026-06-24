<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * InboundRoutingService — the S1.9 core: an InboundMessage → Company (dedup or
 * create) + Deal (in the sales `code='new'` stage). Mirrors the business logic
 * of examples/contracts inbox.py auto_create_deal_from_message (meaning, not
 * code). Counterparty mirror is NOT created (deprecated). Outbound webhooks /
 * round-robin reassignment are out of scope (integrations / M7).
 *
 * Cross-domain boundary: Company is read/created exclusively through
 * CompanyService (findForDedup / create), the Deal through
 * DealService::createInbound. The only direct cross-domain read is the
 * per-channel TG/WA lookup, which walks this domain's InboundMessage to the
 * Deal.company_id of an earlier message — a whitelisted read via the targetDeal
 * relation (the same pattern DealMoveService uses for the company relation).
 */
class InboundRoutingService
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly DealService $deals,
    ) {}

    /**
     * Route a persisted message to a Deal. Mutates and saves $message
     * (target_deal_id / target_deal_created / routing_status) and returns the
     * created/reused Deal, or null when nothing was created (inactive channel,
     * dedup hit, or routing failed). Never throws on routing failure — the lead
     * is preserved as `failed` for manual triage.
     */
    public function route(Channel $channel, InboundMessage $message): ?Deal
    {
        if (! $channel->is_active) {
            // Channel toggled off between submit and routing: accept the message,
            // create nothing. The anonymous sender is never told (E8).
            return null;
        }

        // ---- Webhook-delivery dedup (E1): external_id already routed → link ----
        if ($message->external_id !== null && $message->external_id !== '') {
            $existingDealId = $this->existingDealForExternalId($channel->id, $message->external_id, $message->id);
            if ($existingDealId !== null) {
                $message->forceFill([
                    'target_deal_id' => $existingDealId,
                    'target_deal_created' => false,
                    'routing_status' => RoutingStatus::Dedup,
                ])->save();

                return null;
            }
        }

        // ---- Resolve pipeline + stage (E3): channel defaults → sales fallback ----
        $resolved = $this->resolvePipelineStage($channel);
        if ($resolved === null) {
            $message->forceFill(['routing_status' => RoutingStatus::Failed])->save();
            Log::error('inbox routing failed: no sales pipeline / new stage', [
                'channel_id' => $channel->id,
                'channel_kind' => $channel->kind->value,
                'message_id' => $message->id,
            ]);

            return null;
        }
        [$pipelineId, $stageId] = $resolved;

        $ownerId = $this->resolveOwnerId($channel);
        $source = $this->resolveSource($channel);

        // ---- Company + Deal: created atomically (E3/E8) ----
        // A DB error inside Company/Deal creation must never bubble a 500 to the
        // anonymous sender, and must never leave a half-built Company-without-Deal
        // or a NULL-status inbound row. The whole create sequence runs in one
        // transaction; on any failure we roll it back, then stamp the (already
        // committed) message as `failed` so the lead is preserved for manual
        // triage — exactly mirroring the resolvePipelineStage==null failure path.
        try {
            $deal = DB::transaction(function () use ($channel, $message, $source, $ownerId, $pipelineId, $stageId): Deal {
                // Company: dedup (E2) or create.
                $company = $this->resolveCompany($channel, $message, $source, $ownerId);

                // Deal: created on the company in the resolved stage.
                $deal = $this->deals->createInbound(
                    $company,
                    [
                        'title' => $this->dealTitle($message, $channel),
                        'source' => $source,
                    ],
                    $ownerId,
                    $pipelineId,
                    $stageId,
                );

                $message->forceFill([
                    'target_deal_id' => $deal->id,
                    'target_deal_created' => true,
                    'routing_status' => RoutingStatus::Routed,
                ])->save();

                return $deal;
            });
        } catch (Throwable $e) {
            // The transaction rolled back the partial Company/Deal/stamp. The
            // message row itself was committed before route() ran, so stamp it as
            // `failed` outside the rolled-back transaction and swallow the error.
            $message->forceFill([
                'target_deal_id' => null,
                'target_deal_created' => false,
                'routing_status' => RoutingStatus::Failed,
            ])->save();
            Log::error('inbox routing failed: company/deal creation threw', [
                'channel_id' => $channel->id,
                'channel_kind' => $channel->kind->value,
                'message_id' => $message->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $deal;
    }

    /**
     * Resolve the [pipelineId, stageId] for routing, or null when no sales
     * pipeline / entry stage can be found.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolvePipelineStage(Channel $channel): ?array
    {
        $pipelineId = $channel->default_pipeline_id;
        if ($pipelineId === null) {
            // No channel default: fall back to the first ACTIVE sales pipeline by
            // sort_order/id. Archived pipelines (is_active=false, e.g. the legacy
            // "Продажи" funnel) are excluded so they can never become the routing
            // default — mirrors DealService::defaultSalesPipelineId() /
            // PipelineService::defaultSalesPipeline() / SalesDashboardService.
            $pipelineId = Pipeline::query()
                ->sales()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
            if ($pipelineId === null) {
                return null;
            }
        }

        $stageId = $channel->default_stage_id;
        if ($stageId === null) {
            $stageId = $this->resolveEntryStageId((int) $pipelineId);
            if ($stageId === null) {
                return null;
            }
        }

        return [(int) $pipelineId, (int) $stageId];
    }

    /**
     * Entry stage of a pipeline: the `code='new'` stage, falling back to the
     * first non-won/non-lost/non-hidden stage by sort_order (mirrors
     * DealService::create's fallback). Null when the pipeline has no usable stage.
     */
    private function resolveEntryStageId(int $pipelineId): ?int
    {
        $newCode = (string) config('inbox.sales_stage_code_new', 'new');

        $byCode = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('code', $newCode)
            ->value('id');
        if ($byCode !== null) {
            return (int) $byCode;
        }

        $fallback = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('is_won', false)
            ->where('is_lost', false)
            ->where('hidden_by_default', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        return $fallback !== null ? (int) $fallback : null;
    }

    /**
     * Resolve a concrete owner. deals.owner_user_id is NOT NULL, so when the
     * channel has no static default_owner_id we fall back to the first
     * admin/director user (the same triage owner inbound triage lands on). Null
     * only when no user exists at all (impossible in a seeded system).
     */
    private function resolveOwnerId(Channel $channel): ?int
    {
        if ($channel->default_owner_id !== null) {
            return (int) $channel->default_owner_id;
        }

        return User::query()->orderBy('id')->value('id');
    }

    private function resolveSource(Channel $channel): string
    {
        $source = $channel->default_lead_source;

        return $source !== null && $source !== '' ? $source : $channel->kind->defaultLeadSource();
    }

    /**
     * Dedup or create the Company. TG/WA dedup by (channel, from_identifier) via
     * a prior message; other kinds by contact (email/phone). The owner of an
     * existing company is never overwritten (we don't steal someone's card).
     */
    private function resolveCompany(Channel $channel, InboundMessage $message, string $source, ?int $ownerId): Company
    {
        $email = $this->deriveEmail($message->from_identifier);
        $phone = $this->derivePhone($message->from_identifier);

        $company = null;

        if ($channel->kind->dedupsByChannelIdentifier() && $message->from_identifier !== null) {
            $company = $this->companyByChannelIdentifier($channel->id, $message->from_identifier);
        }

        if ($company === null) {
            $company = $this->companies->findForDedup($email, $phone);
        }

        if ($company !== null) {
            return $company;
        }

        // New company. Name = from_name > from_identifier > subject > fallback.
        $name = $this->companyName($message, $channel);

        return $this->companies->create([
            'name' => $name,
            'legal_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'source' => $source,
            'owner_user_id' => $ownerId,
            'tags' => [],
            'extra_fields' => [],
        ], $this->ownerUser($ownerId));
    }

    /**
     * Find the Company of the earliest routed message from the same channel +
     * identifier (case-insensitive). Walks this domain's InboundMessage to the
     * routed Deal.company_id — a whitelisted cross-domain read via the relation.
     */
    private function companyByChannelIdentifier(int $channelId, string $identifier): ?Company
    {
        $ident = trim($identifier);
        if ($ident === '') {
            return null;
        }

        $companyId = InboundMessage::query()
            ->where('channel_id', $channelId)
            ->whereRaw('LOWER(from_identifier) = ?', [mb_strtolower($ident)])
            ->whereNotNull('target_deal_id')
            ->join('deals', 'deals.id', '=', 'inbound_messages.target_deal_id')
            ->orderBy('inbound_messages.id')
            ->value('deals.company_id');

        return $companyId !== null ? Company::find($companyId) : null;
    }

    /**
     * The earliest Deal id already routed for (channel, external_id), excluding
     * the current message. Null when none — the first routing of this id.
     */
    private function existingDealForExternalId(int $channelId, string $externalId, int $currentMessageId): ?int
    {
        $dealId = InboundMessage::query()
            ->where('channel_id', $channelId)
            ->where('external_id', $externalId)
            ->where('id', '!=', $currentMessageId)
            ->whereNotNull('target_deal_id')
            ->orderBy('id')
            ->value('target_deal_id');

        return $dealId !== null ? (int) $dealId : null;
    }

    private function dealTitle(InboundMessage $message, Channel $channel): string
    {
        $raw = $message->from_name
            ?? $message->from_identifier
            ?? $message->subject
            ?? "Лид из {$channel->name}";

        return mb_substr($raw, 0, 255);
    }

    private function companyName(InboundMessage $message, Channel $channel): string
    {
        return $this->dealTitle($message, $channel);
    }

    private function deriveEmail(?string $identifier): ?string
    {
        return $identifier !== null && str_contains($identifier, '@') ? $identifier : null;
    }

    private function derivePhone(?string $identifier): ?string
    {
        return $identifier !== null && str_starts_with($identifier, '+') ? $identifier : null;
    }

    /**
     * The User to pass to CompanyService::create as the creator (for owner /
     * department auto-stamp). Falls back to the resolved owner.
     */
    private function ownerUser(?int $ownerId): User
    {
        $user = $ownerId !== null ? User::find($ownerId) : null;

        return $user ?? User::query()->orderBy('id')->firstOrFail();
    }

    /** Allowed channel kinds (referenced for completeness / future use). */
    public static function channelKinds(): array
    {
        return array_map(static fn (ChannelKind $k): string => $k->value, ChannelKind::cases());
    }
}
