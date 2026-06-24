<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * M-2 regression guard: a DB/throwable during Company/Deal creation must never
 * bubble a 500 to the anonymous sender, must never leave an orphan NULL-status
 * inbound row, and must roll back any partial Company so we don't accumulate
 * Company-without-Deal junk. The lead is preserved as `failed` for manual triage.
 */
class InboundRoutingFailureTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    public function test_form_submit_deal_creation_throws_returns_ack_and_stamps_failed(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = $this->makeWebFormChannel($owner, $pipeline);
        $this->makeForm($channel, 'lead-form');

        $this->bindThrowingDealService();

        // Anonymous sender gets an idempotent ack (NOT a 500); deal_created=false.
        $this->postJson('/api/forms/public/lead-form/submit', [
            'name' => 'Иван', 'email' => 'boom@example.com',
        ])->assertCreated()->assertJsonPath('data.deal_created', false);

        // No deal, no orphaned half-built company (transaction rolled back).
        $this->assertSame(0, Deal::count());
        $this->assertNull(Company::query()->where('email', 'boom@example.com')->first());

        // The message row survives and is stamped `failed` (never NULL status).
        $message = InboundMessage::query()->where('channel_id', $channel->id)->firstOrFail();
        $this->assertSame('failed', $message->routing_status->value);
        $this->assertNull($message->target_deal_id);
        $this->assertFalse((bool) $message->target_deal_created);
    }

    public function test_webhook_deal_creation_throws_returns_201_ack_not_500(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = Channel::factory()->kind(ChannelKind::Api)->create([
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $this->newStageId($pipeline),
        ]);

        $this->bindThrowingDealService();

        $this->postJson("/api/inbox/webhook/{$channel->id}", [
            'external_id' => 'ext-boom', 'from_identifier' => 'boom@example.com',
        ], ['X-Channel-Token' => $channel->secret_token])
            ->assertCreated()
            ->assertJsonPath('data.deal_created', false);

        $this->assertSame(0, Deal::count());

        $message = InboundMessage::query()->where('channel_id', $channel->id)->firstOrFail();
        $this->assertSame('failed', $message->routing_status->value);
        $this->assertNull($message->target_deal_id);
    }

    /**
     * Bind a DealService whose createInbound always throws, simulating a transient
     * DB error deep in the create sequence.
     */
    private function bindThrowingDealService(): void
    {
        $stub = new class extends DealService
        {
            public function __construct() {}

            public function createInbound(
                Company $company,
                array $opts,
                ?int $ownerId,
                int $pipelineId,
                int $stageId,
            ): Deal {
                throw new RuntimeException('simulated DB failure');
            }
        };

        $this->app->instance(DealService::class, $stub);
    }
}
