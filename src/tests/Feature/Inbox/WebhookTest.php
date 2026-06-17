<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    public function test_webhook_valid_token_creates_company_and_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = Channel::factory()->kind(ChannelKind::Api)->create([
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $this->newStageId($pipeline),
        ]);

        $response = $this->postJson("/api/inbox/webhook/{$channel->id}", [
            'from_identifier' => 'lead@example.com',
            'from_name' => 'Webhook Lead',
        ], ['X-Channel-Token' => $channel->secret_token])->assertCreated();

        $this->assertTrue($response->json('data.deal_created'));
        $dealId = $response->json('data.deal_id');
        $deal = Deal::findOrFail($dealId);
        $this->assertSame($this->newStageId($pipeline), $deal->stage_id);
        $this->assertSame('lead@example.com', Company::find($deal->company_id)->email);
    }

    public function test_webhook_missing_token_401(): void
    {
        $channel = Channel::factory()->create();

        $this->postJson("/api/inbox/webhook/{$channel->id}", ['from_name' => 'x'])
            ->assertStatus(401);
    }

    public function test_webhook_wrong_token_403(): void
    {
        $channel = Channel::factory()->create();

        $this->postJson("/api/inbox/webhook/{$channel->id}", ['from_name' => 'x'], [
            'X-Channel-Token' => 'wrong-token',
        ])->assertStatus(403);
    }

    public function test_webhook_inactive_channel_503(): void
    {
        $channel = Channel::factory()->inactive()->create();

        $this->postJson("/api/inbox/webhook/{$channel->id}", ['from_name' => 'x'], [
            'X-Channel-Token' => $channel->secret_token,
        ])->assertStatus(503);
    }

    public function test_webhook_duplicate_external_id_dedups(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = Channel::factory()->kind(ChannelKind::Api)->create([
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $this->newStageId($pipeline),
        ]);

        $payload = ['external_id' => 'ext-123', 'from_identifier' => 'dup@example.com', 'from_name' => 'Dup'];
        $headers = ['X-Channel-Token' => $channel->secret_token];

        $first = $this->postJson("/api/inbox/webhook/{$channel->id}", $payload, $headers)->assertCreated();
        $firstDealId = $first->json('data.deal_id');
        $this->assertTrue($first->json('data.deal_created'));

        $second = $this->postJson("/api/inbox/webhook/{$channel->id}", $payload, $headers);

        // DB partial-UNIQUE catches the retry → idempotent reply, no new deal.
        $this->assertFalse($second->json('data.deal_created'));
        $this->assertSame($firstDealId, $second->json('data.deal_id'));
        $this->assertSame(1, Deal::count());
    }

    public function test_webhook_tg_kind_dedups_by_channel_identifier(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $channel = Channel::factory()->kind(ChannelKind::Tg)->create([
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $this->newStageId($pipeline),
        ]);
        $headers = ['X-Channel-Token' => $channel->secret_token];

        // First TG message from chat handle (not an email/+phone).
        $first = $this->postJson("/api/inbox/webhook/{$channel->id}", [
            'external_id' => 'tg-1', 'from_identifier' => '@ivan', 'from_name' => 'Иван',
        ], $headers)->assertCreated();
        $firstDealId = $first->json('data.deal_id');
        $companyId = Deal::findOrFail($firstDealId)->company_id;

        // Second TG message, same chat handle, different external_id → new deal but
        // reuses the SAME company (per-channel identifier dedup).
        $second = $this->postJson("/api/inbox/webhook/{$channel->id}", [
            'external_id' => 'tg-2', 'from_identifier' => '@ivan', 'from_name' => 'Иван',
        ], $headers)->assertCreated();

        $this->assertTrue($second->json('data.deal_created'));
        $this->assertSame(2, Deal::count());
        $this->assertSame(1, Company::count());
        $this->assertSame($companyId, Deal::findOrFail($second->json('data.deal_id'))->company_id);
    }
}
