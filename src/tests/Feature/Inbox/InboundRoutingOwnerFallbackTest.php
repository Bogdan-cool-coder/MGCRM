<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Э3 finding 6 — InboundRoutingService::resolveOwnerId owner fallback.
 *
 * When a channel carries no static default_owner_id, an unrouted lead must land
 * on an admin/director (a privileged triage account), NOT on whichever user
 * happens to have the lowest id (which could be a manager/accountant). The
 * docblock promised this; the role filter now enforces it via spatie on the
 * sanctum guard.
 */
class InboundRoutingOwnerFallbackTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    private function makeChannelWithoutOwner(int $pipelineId, int $stageId): Channel
    {
        return Channel::factory()->create([
            'kind' => ChannelKind::WebForm,
            'default_owner_id' => null,
            'default_pipeline_id' => $pipelineId,
            'default_stage_id' => $stageId,
            'is_active' => true,
        ]);
    }

    private function makeMessage(Channel $channel): InboundMessage
    {
        return InboundMessage::create([
            'channel_id' => $channel->id,
            'external_id' => 'ext-owner-'.uniqid(),
            'from_identifier' => 'lead@example.com',
            'from_name' => 'Owner Fallback Lead',
            'body' => 'Hello',
            'raw_payload' => [],
        ]);
    }

    public function test_fallback_owner_is_a_privileged_user_not_the_lowest_id(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->newStageId($pipeline);

        // A manager is created FIRST (lowest id) — a role-blind fallback would
        // wrongly pick it.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $director = User::factory()->create(['role' => Role::Director]);

        $this->assertLessThan($director->id, $manager->id, 'manager must have the lower id');

        $channel = $this->makeChannelWithoutOwner($pipeline->id, $stageId);
        $deal = app(InboundRoutingService::class)->route($channel, $this->makeMessage($channel));

        $this->assertNotNull($deal);
        $this->assertSame(
            $director->id,
            $deal->owner_user_id,
            'Fallback owner must be the admin/director, not the lower-id manager.',
        );
    }

    public function test_fallback_prefers_admin_over_director_by_id_order(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->newStageId($pipeline);

        // Both privileged; admin created first → lower id → chosen (orderBy id).
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Director]);
        // A manager exists too but must never win.
        User::factory()->create(['role' => Role::Manager]);

        $channel = $this->makeChannelWithoutOwner($pipeline->id, $stageId);
        $deal = app(InboundRoutingService::class)->route($channel, $this->makeMessage($channel));

        $this->assertSame($admin->id, $deal->owner_user_id);
    }

    public function test_channel_owner_takes_precedence_over_fallback(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->newStageId($pipeline);

        User::factory()->create(['role' => Role::Director]);
        $channelOwner = User::factory()->create(['role' => Role::Manager]);

        $channel = Channel::factory()->create([
            'kind' => ChannelKind::WebForm,
            'default_owner_id' => $channelOwner->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $stageId,
            'is_active' => true,
        ]);

        $deal = app(InboundRoutingService::class)->route($channel, $this->makeMessage($channel));

        // An explicit channel owner is honoured even if it is a plain manager —
        // the role filter only governs the FALLBACK.
        $this->assertSame($channelOwner->id, $deal->owner_user_id);
    }

    public function test_degrades_to_any_user_when_no_privileged_user_exists(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->newStageId($pipeline);

        // No admin/director seeded — only a manager. deals.owner_user_id is NOT
        // NULL, so the fallback must still resolve SOME owner rather than fail.
        $manager = User::factory()->create(['role' => Role::Manager]);

        $channel = $this->makeChannelWithoutOwner($pipeline->id, $stageId);
        $deal = app(InboundRoutingService::class)->route($channel, $this->makeMessage($channel));

        $this->assertNotNull($deal);
        $this->assertSame($manager->id, $deal->owner_user_id);

        $message = InboundMessage::query()
            ->where('channel_id', $channel->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(RoutingStatus::Routed, $message->routing_status);
    }
}
