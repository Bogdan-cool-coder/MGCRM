<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inbox triage UI backend: Gmail-style read state, read-side filters, the
 * embedded channel/target_deal resource shape, and «Переобработать» reprocess.
 */
class InboundMessageTriageTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // 1) READ / UNREAD state
    // -------------------------------------------------------------------------

    public function test_read_sets_read_at_and_is_idempotent(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['read_at' => null]);

        $first = $this->postJson("/api/inbox/{$message->id}/read")->assertOk();
        $this->assertNotNull($first->json('data.read_at'));
        $stamp = $first->json('data.read_at');

        // Idempotent: re-calling does not move the timestamp.
        $second = $this->postJson("/api/inbox/{$message->id}/read")->assertOk();
        $this->assertSame($stamp, $second->json('data.read_at'));

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_unread_clears_read_at_and_is_idempotent(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['read_at' => now()]);

        $this->postJson("/api/inbox/{$message->id}/unread")
            ->assertOk()
            ->assertJsonPath('data.read_at', null);

        // Idempotent on an already-unread message.
        $this->postJson("/api/inbox/{$message->id}/unread")
            ->assertOk()
            ->assertJsonPath('data.read_at', null);

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_detail_does_not_auto_mark_read(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['read_at' => null]);

        $this->getJson("/api/inbox/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.read_at', null);

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_unread_count_counts_unread_within_scope(): void
    {
        $this->actAsAdmin();
        InboundMessage::factory()->count(3)->create(['read_at' => null]);
        InboundMessage::factory()->count(2)->create(['read_at' => now()]);

        $this->getJson('/api/inbox/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 3);
    }

    // -------------------------------------------------------------------------
    // 2) INDEX filters
    // -------------------------------------------------------------------------

    public function test_index_q_filter_matches_substring_across_sender_subject_body(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();
        InboundMessage::factory()->for($channel)->create(['from_name' => 'Acme Corp', 'subject' => null, 'body' => null]);
        InboundMessage::factory()->for($channel)->create(['from_name' => 'Zzz', 'subject' => 'Order from Acme', 'body' => null]);
        InboundMessage::factory()->for($channel)->create(['from_name' => 'Other', 'subject' => null, 'body' => 'no match here']);

        $this->getJson('/api/inbox?q=acme')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_has_deal_filter_narrows_by_target_deal_presence(): void
    {
        $this->actAsAdmin();
        $pipeline = $this->seedSalesPipeline();
        $channel = $this->makeWebFormChannel(null, $pipeline);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->newStageId($pipeline),
        ]);

        InboundMessage::factory()->for($channel)->create(['target_deal_id' => $deal->id]);
        InboundMessage::factory()->for($channel)->create(['target_deal_id' => null]);

        $this->getJson('/api/inbox?has_deal=true')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inbox?has_deal=false')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_unread_filter_narrows_by_read_state(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();
        InboundMessage::factory()->for($channel)->count(2)->create(['read_at' => null]);
        InboundMessage::factory()->for($channel)->create(['read_at' => now()]);

        $this->getJson('/api/inbox?unread=true')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inbox?unread=false')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_date_range_filter_narrows_by_received_at(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();
        // received_at stored UTC; the filter interprets the bound in operational tz.
        InboundMessage::factory()->for($channel)->create(['received_at' => Carbon::parse('2026-06-01 10:00:00', 'UTC')]);
        InboundMessage::factory()->for($channel)->create(['received_at' => Carbon::parse('2026-06-15 10:00:00', 'UTC')]);
        InboundMessage::factory()->for($channel)->create(['received_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC')]);

        $this->getJson('/api/inbox?date_from=2026-06-10&date_to=2026-06-20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.received_at', Carbon::parse('2026-06-15 10:00:00', 'UTC')->toISOString());
    }

    public function test_index_keeps_channel_and_routing_status_filters(): void
    {
        $this->actAsAdmin();
        $a = Channel::factory()->create();
        $b = Channel::factory()->create();
        InboundMessage::factory()->for($a)->create(['routing_status' => RoutingStatus::Routed]);
        InboundMessage::factory()->for($a)->create(['routing_status' => RoutingStatus::Failed]);
        InboundMessage::factory()->for($b)->create(['routing_status' => RoutingStatus::Failed]);

        $this->getJson("/api/inbox?channel_id={$a->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/inbox?channel_id={$a->id}&routing_status=failed")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_malformed_date_returns_422_not_500(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/inbox?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        // A valid YYYY-MM-DD still filters (regression guard for the validation).
        $channel = Channel::factory()->create();
        InboundMessage::factory()->for($channel)->create(['received_at' => Carbon::parse('2026-06-15 10:00:00', 'UTC')]);
        InboundMessage::factory()->for($channel)->create(['received_at' => Carbon::parse('2026-06-30 10:00:00', 'UTC')]);

        $this->getJson('/api/inbox?date_from=2026-06-10&date_to=2026-06-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Resource shape + N+1
    // -------------------------------------------------------------------------

    public function test_resource_exposes_raw_payload_to_inbox_manage_user(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create([
            'raw_payload' => ['header' => 'x-internal', 'foo' => 'bar'],
        ]);

        $this->getJson("/api/inbox/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.raw_payload.header', 'x-internal')
            ->assertJsonPath('data.raw_payload.foo', 'bar');
    }

    public function test_resource_carries_channel_target_deal_and_read_at_without_n_plus_one(): void
    {
        $admin = $this->actAsAdmin();
        $pipeline = $this->seedSalesPipeline();
        $channel = $this->makeWebFormChannel($admin, $pipeline);
        $company = Company::factory()->create();
        $stageId = $this->newStageId($pipeline);

        // Three messages, each routed to its own deal — proves batching, not 1 row.
        foreach (range(1, 3) as $i) {
            $deal = Deal::factory()->create([
                'company_id' => $company->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stageId,
                'title' => "Deal {$i}",
            ]);
            InboundMessage::factory()->for($channel)->create([
                'target_deal_id' => $deal->id,
                'read_at' => null,
            ]);
        }

        DB::enableQueryLog();
        $response = $this->getJson('/api/inbox')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.channel.id', $channel->id)
            ->assertJsonPath('data.0.channel.name', $channel->name)
            ->assertJsonPath('data.0.channel.kind', $channel->kind->value)
            ->assertJsonPath('data.0.read_at', null);

        $this->assertNotNull($response->json('data.0.target_deal.id'));
        $this->assertNotNull($response->json('data.0.target_deal.title'));
        $this->assertSame($stageId, $response->json('data.0.target_deal.stage.id'));

        // Eager-loaded: a small constant query count regardless of row count.
        // (auth + count + page + channel batch + deal batch + stage batch ≈ 6-8).
        $this->assertLessThanOrEqual(10, $queryCount, "N+1 detected: {$queryCount} queries for 3 rows");
    }

    // -------------------------------------------------------------------------
    // 3) REPROCESS («Переобработать»)
    // -------------------------------------------------------------------------

    public function test_reroute_on_failed_message_creates_deal_and_flips_to_routed(): void
    {
        $this->actAsAdmin();
        $pipeline = $this->seedSalesPipeline();
        // Channel wired to the sales pipeline; message previously failed.
        $channel = $this->makeWebFormChannel(null, $pipeline);
        $message = InboundMessage::factory()->for($channel)->create([
            'from_identifier' => 'reroute@example.com',
            'from_name' => 'Reroute Lead',
            'routing_status' => RoutingStatus::Failed,
            'target_deal_id' => null,
            'target_deal_created' => false,
        ]);

        $this->assertSame(0, Deal::count());

        $this->postJson("/api/inbox/{$message->id}/reroute")
            ->assertOk()
            ->assertJsonPath('data.routing_status', 'routed')
            ->assertJsonPath('data.target_deal_created', true);

        $this->assertSame(1, Deal::count());
        $fresh = $message->fresh();
        $this->assertSame(RoutingStatus::Routed, $fresh->routing_status);
        $this->assertNotNull($fresh->target_deal_id);
    }

    public function test_reroute_when_no_pipeline_stays_failed_without_500(): void
    {
        $this->actAsAdmin();
        // No sales pipeline seeded + channel has no pipeline/stage defaults →
        // routing cannot resolve a pipeline. Must NOT 500; stays failed.
        $channel = Channel::factory()->create([
            'default_pipeline_id' => null,
            'default_stage_id' => null,
        ]);
        $message = InboundMessage::factory()->for($channel)->create([
            'from_identifier' => 'orphan@example.com',
            'routing_status' => RoutingStatus::Failed,
            'target_deal_id' => null,
        ]);

        $this->postJson("/api/inbox/{$message->id}/reroute")
            ->assertOk()
            ->assertJsonPath('data.routing_status', 'failed')
            ->assertJsonPath('data.target_deal_id', null);

        $this->assertSame(0, Deal::count());
    }

    /**
     * Reroute is idempotent: re-running it on an already-routed message does NOT
     * create a second Deal (the external_id dedup short-circuit links to the deal
     * the message already carries). The DB partial-unique index on
     * (channel_id, external_id) already prevents two rows sharing an external_id,
     * so the dedup short-circuit is the realizable safeguard against duplicates.
     */
    public function test_reroute_on_already_routed_external_id_message_does_not_duplicate(): void
    {
        $this->actAsAdmin();
        $pipeline = $this->seedSalesPipeline();
        $channel = $this->makeWebFormChannel(null, $pipeline);

        // First reroute on a failed message carrying an external_id → creates one deal.
        $message = InboundMessage::factory()->for($channel)->create([
            'external_id' => 'ext-keep',
            'from_identifier' => 'keep@example.com',
            'routing_status' => RoutingStatus::Failed,
            'target_deal_id' => null,
        ]);

        $this->postJson("/api/inbox/{$message->id}/reroute")
            ->assertOk()
            ->assertJsonPath('data.routing_status', 'routed');

        $this->assertSame(1, Deal::count());
        $dealId = $message->fresh()->target_deal_id;
        $this->assertNotNull($dealId);

        // Second reroute → dedup short-circuit links to the same deal, no new one.
        $this->postJson("/api/inbox/{$message->id}/reroute")
            ->assertOk()
            ->assertJsonPath('data.target_deal_id', $dealId);

        $this->assertSame(1, Deal::count());
    }

    // -------------------------------------------------------------------------
    // authz
    // -------------------------------------------------------------------------

    public function test_manager_gets_403_on_read_unread_reroute(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $message = InboundMessage::factory()->create();

        $this->postJson("/api/inbox/{$message->id}/read")->assertForbidden();
        $this->postJson("/api/inbox/{$message->id}/unread")->assertForbidden();
        $this->postJson("/api/inbox/{$message->id}/reroute")->assertForbidden();
        $this->getJson('/api/inbox/unread-count')->assertForbidden();
    }
}
