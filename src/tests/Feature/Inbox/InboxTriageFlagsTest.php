<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mail СРЕЗ B backend: star / important / snooze triage flags, the snooze-aware
 * unread-count formula, and GET /api/inbox/counts. See
 * docs/contracts/inbox-mail-slice-b-contract.md §4.1-§4.5/§8.
 */
class InboxTriageFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // 1) STAR
    // -------------------------------------------------------------------------

    public function test_star_sets_starred_at_and_is_idempotent(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['starred_at' => null]);

        $first = $this->postJson("/api/inbox/{$message->id}/star")->assertOk();
        $stamp = $first->json('data.starred_at');
        $this->assertNotNull($stamp);

        // Idempotent: re-calling does not move the timestamp.
        $second = $this->postJson("/api/inbox/{$message->id}/star")->assertOk();
        $this->assertSame($stamp, $second->json('data.starred_at'));
    }

    public function test_unstar_clears_starred_at_and_is_idempotent(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->starred()->create();

        $this->deleteJson("/api/inbox/{$message->id}/star")
            ->assertOk()
            ->assertJsonPath('data.starred_at', null);

        $this->deleteJson("/api/inbox/{$message->id}/star")
            ->assertOk()
            ->assertJsonPath('data.starred_at', null);

        $this->assertNull($message->fresh()->starred_at);
    }

    public function test_star_and_unstar_require_inbox_manage(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $message = InboundMessage::factory()->create();

        $this->postJson("/api/inbox/{$message->id}/star")->assertForbidden();
        $this->deleteJson("/api/inbox/{$message->id}/star")->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 2) IMPORTANT
    // -------------------------------------------------------------------------

    public function test_important_on_off_is_idempotent(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['important' => false]);

        $this->postJson("/api/inbox/{$message->id}/important")
            ->assertOk()
            ->assertJsonPath('data.important', true);

        $this->postJson("/api/inbox/{$message->id}/important")
            ->assertOk()
            ->assertJsonPath('data.important', true);

        $this->deleteJson("/api/inbox/{$message->id}/important")
            ->assertOk()
            ->assertJsonPath('data.important', false);

        $this->deleteJson("/api/inbox/{$message->id}/important")
            ->assertOk()
            ->assertJsonPath('data.important', false);

        $this->assertFalse($message->fresh()->important);
    }

    public function test_important_requires_inbox_manage(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $message = InboundMessage::factory()->create();

        $this->postJson("/api/inbox/{$message->id}/important")->assertForbidden();
        $this->deleteJson("/api/inbox/{$message->id}/important")->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 3) SNOOZE
    // -------------------------------------------------------------------------

    public function test_snooze_sets_snoozed_until_for_a_future_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 08:00:00', 'UTC'));
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create(['snoozed_until' => null]);

        $this->postJson("/api/inbox/{$message->id}/snooze", ['until' => '2026-07-05T09:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.snoozed_until', Carbon::parse('2026-07-05T09:00:00Z')->toISOString());
    }

    public function test_snooze_in_the_past_is_rejected_with_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 08:00:00', 'UTC'));
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create();

        $this->postJson("/api/inbox/{$message->id}/snooze", ['until' => '2026-07-01T09:00:00Z'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('until');
    }

    public function test_unsnooze_clears_snoozed_until(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->snoozed()->create();

        $this->deleteJson("/api/inbox/{$message->id}/snooze")
            ->assertOk()
            ->assertJsonPath('data.snoozed_until', null);

        $this->assertNull($message->fresh()->snoozed_until);
    }

    public function test_snooze_requires_inbox_manage(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $message = InboundMessage::factory()->create();

        $this->postJson("/api/inbox/{$message->id}/snooze", ['until' => now()->addDay()->toISOString()])->assertForbidden();
        $this->deleteJson("/api/inbox/{$message->id}/snooze")->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 4) SNOOZE-HIDING semantics (contract §4.3/§4.4 — the critical test)
    // -------------------------------------------------------------------------

    public function test_actively_snoozed_message_is_hidden_from_plain_inbox_but_visible_in_its_folder(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();

        $snoozed = InboundMessage::factory()->for($channel)->snoozed()->create();
        $normal = InboundMessage::factory()->for($channel)->create();

        // Plain «Входящие» (no explicit triage filter) hides the actively-snoozed one.
        $response = $this->getJson('/api/inbox')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($snoozed->id));
        $this->assertTrue($ids->contains($normal->id));

        // «Отложенные» (?snoozed=1) shows it.
        $this->getJson('/api/inbox?snoozed=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $snoozed->id);
    }

    public function test_snooze_hiding_does_not_apply_to_failed_deals_starred_or_important_folders(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();

        $snoozedFailed = InboundMessage::factory()->for($channel)->snoozed()->create(['routing_status' => RoutingStatus::Failed]);
        $snoozedStarred = InboundMessage::factory()->for($channel)->snoozed()->starred()->create();
        $snoozedImportant = InboundMessage::factory()->for($channel)->snoozed()->important()->create();

        $this->getJson('/api/inbox?routing_status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $snoozedFailed->id);

        $this->getJson('/api/inbox?starred=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $snoozedStarred->id);

        $this->getJson('/api/inbox?important=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $snoozedImportant->id);
    }

    public function test_message_reappears_in_inbox_once_snoozed_until_has_passed(): void
    {
        $this->actAsAdmin();
        $channel = Channel::factory()->create();
        $returned = InboundMessage::factory()->for($channel)->snoozedPast()->create();

        $response = $this->getJson('/api/inbox')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($returned->id));

        // And it does NOT show in «Отложенные» any more (no longer actively snoozed).
        $this->getJson('/api/inbox?snoozed=1')->assertOk()->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------------------
    // 5) unread-count (snooze-aware regression + new)
    // -------------------------------------------------------------------------

    public function test_unread_count_excludes_actively_snoozed_unread_messages(): void
    {
        $this->actAsAdmin();
        InboundMessage::factory()->count(2)->create(['read_at' => null]);
        InboundMessage::factory()->snoozed()->create(['read_at' => null]);
        InboundMessage::factory()->snoozedPast()->create(['read_at' => null]);

        // 2 plain unread + 1 returned-from-snooze unread = 3; the actively-snoozed one is excluded.
        $this->getJson('/api/inbox/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 3);
    }

    // -------------------------------------------------------------------------
    // 6) counts
    // -------------------------------------------------------------------------

    public function test_counts_endpoint_reports_all_folder_and_channel_aggregates(): void
    {
        $admin = $this->actAsAdmin();
        $tgChannel = Channel::factory()->create(['kind' => 'tg']);
        $emailChannel = Channel::factory()->create(['kind' => 'email']);

        // Every row below is explicitly marked read UNLESS it is meant to count
        // toward inbox_unread/channels — the factory defaults read_at to null.

        // inbox_unread: 2 plain unread (tg) + 1 returned-from-snooze unread (email) = 3.
        InboundMessage::factory()->for($tgChannel)->count(2)->create(['read_at' => null]);
        InboundMessage::factory()->for($emailChannel)->snoozedPast()->create(['read_at' => null]);
        // Actively snoozed unread — excluded from inbox_unread AND counted in snoozed,
        // but still counted in per-channel unread (channel unread is NOT snooze-filtered).
        InboundMessage::factory()->for($emailChannel)->snoozed()->create(['read_at' => null]);

        InboundMessage::factory()->for($tgChannel)->count(3)->starred()->create(['read_at' => now()]);
        InboundMessage::factory()->for($emailChannel)->important()->create(['read_at' => now()]);
        InboundMessage::factory()->for($tgChannel)->create(['routing_status' => RoutingStatus::Failed, 'read_at' => now()]);
        InboundMessage::factory()->for($emailChannel)->create(['routing_status' => RoutingStatus::Failed, 'read_at' => now()]);
        InboundMessage::factory()->for($tgChannel)->create(['target_deal_id' => null, 'read_at' => now()]);

        $this->postJson('/api/inbox/drafts', ['subject' => 'note'])->assertCreated();

        $response = $this->getJson('/api/inbox/counts')->assertOk();

        $this->assertSame(3, $response->json('data.folders.inbox_unread'));
        $this->assertSame(3, $response->json('data.folders.starred'));
        $this->assertSame(1, $response->json('data.folders.important'));
        $this->assertSame(2, $response->json('data.folders.failed'));
        $this->assertSame(1, $response->json('data.folders.snoozed'));
        $this->assertSame(1, $response->json('data.folders.drafts'));
        $this->assertIsInt($response->json('data.folders.in_deals'));

        // per-channel unread: tg has 2 unread (the plain pair); email has 2 unread
        // (returned-from-snooze + actively-snoozed) — read_at is what counts here,
        // channel unread is NOT snooze-filtered (contract shows raw per-channel unread).
        $this->assertSame(2, $response->json('data.channels.tg'));
        $this->assertSame(2, $response->json('data.channels.email'));
    }

    public function test_counts_requires_inbox_manage(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/inbox/counts')->assertForbidden();
    }
}
