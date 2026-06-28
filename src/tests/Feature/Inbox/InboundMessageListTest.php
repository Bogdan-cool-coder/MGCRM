<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InboundMessageListTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_inbox_filter_by_routing_status(): void
    {
        $channel = Channel::factory()->create();
        InboundMessage::factory()->for($channel)->create(['routing_status' => RoutingStatus::Routed]);
        InboundMessage::factory()->for($channel)->create(['routing_status' => RoutingStatus::Failed]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->getJson('/api/inbox')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/inbox?routing_status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.routing_status', 'failed');
    }

    public function test_manager_cannot_list_inbox(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/inbox')->assertForbidden();
    }

    public function test_admin_can_show_inbound_message(): void
    {
        $message = InboundMessage::factory()->create(['from_name' => 'Shown']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->getJson("/api/inbox/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.from_name', 'Shown');
    }

    public function test_per_page_is_clamped_to_a_sane_max(): void
    {
        // #15: an absurd per_page must not pull the whole table into one page.
        // 105 rows + per_page=100000 → the page is capped at 100.
        $channel = Channel::factory()->create();
        InboundMessage::factory()->for($channel)->count(105)->create();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $response = $this->getJson('/api/inbox?per_page=100000')->assertOk();

        $this->assertCount(100, $response->json('data'));
        $this->assertSame(100, $response->json('meta.per_page'));
    }
}
