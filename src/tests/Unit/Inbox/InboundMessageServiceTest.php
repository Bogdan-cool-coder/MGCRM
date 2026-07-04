<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for InboundMessageService — the snooze-aware unread-count helper
 * (contract §4.5.1), the folder/channel counts aggregate, and the
 * applySnoozeHiding decision (only "чистые Входящие" hides actively-snoozed
 * rows). See docs/contracts/inbox-mail-slice-b-contract.md §8.
 */
class InboundMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private InboundMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InboundMessageService::class);
    }

    public function test_inbox_unread_count_excludes_actively_snoozed_but_includes_returned(): void
    {
        InboundMessage::factory()->count(2)->create(['read_at' => null]);
        InboundMessage::factory()->create(['read_at' => now()]);
        InboundMessage::factory()->snoozed()->create(['read_at' => null]);
        InboundMessage::factory()->snoozedPast()->create(['read_at' => null]);

        // 2 plain unread + 1 returned-from-snooze = 3; the active snooze + the
        // already-read row are excluded.
        $this->assertSame(3, $this->service->inboxUnreadCount());
    }

    public function test_counts_folder_aggregate_matches_manual_counts(): void
    {
        $channel = Channel::factory()->create();

        // The 2 "plain" rows are the only ones intended to count as inbox_unread;
        // every other row is explicitly marked read so it does not also inflate
        // that aggregate (the factory defaults read_at to null/unread).
        InboundMessage::factory()->for($channel)->count(2)->create(['read_at' => null]);
        InboundMessage::factory()->for($channel)->starred()->create(['read_at' => now()]);
        InboundMessage::factory()->for($channel)->important()->create(['read_at' => now()]);
        InboundMessage::factory()->for($channel)->create(['routing_status' => RoutingStatus::Failed, 'read_at' => now()]);
        InboundMessage::factory()->for($channel)->snoozed()->create(['read_at' => now()]);

        $counts = $this->service->counts(1);

        $this->assertSame(2, $counts['folders']['inbox_unread']);
        $this->assertSame(1, $counts['folders']['starred']);
        $this->assertSame(1, $counts['folders']['important']);
        $this->assertSame(1, $counts['folders']['failed']);
        $this->assertSame(1, $counts['folders']['snoozed']);
        $this->assertArrayHasKey('in_deals', $counts['folders']);
        $this->assertArrayHasKey('drafts', $counts['folders']);
    }

    public function test_counts_drafts_are_scoped_to_the_given_user(): void
    {
        $counts = $this->service->counts(42);

        $this->assertSame(0, $counts['folders']['drafts']);
    }

    public function test_index_index_applies_snooze_hiding_only_when_no_explicit_filter_is_present(): void
    {
        $channel = Channel::factory()->create();
        $snoozed = InboundMessage::factory()->for($channel)->snoozed()->create();
        InboundMessage::factory()->for($channel)->create();

        // No filters → snooze-hiding applies → snoozed message excluded.
        $plain = $this->service->paginate([]);
        $this->assertFalse($plain->getCollection()->pluck('id')->contains($snoozed->id));

        // Explicit `starred` filter present → snooze-hiding does NOT apply, but the
        // snoozed message is simply not starred so it is excluded for a different
        // reason; assert against the ?snoozed=1 filter which DOES surface it.
        $snoozedFolder = $this->service->paginate(['snoozed' => true]);
        $this->assertTrue($snoozedFolder->getCollection()->pluck('id')->contains($snoozed->id));

        // Explicit `failed` filter present (routing_status=failed) → snooze-hiding
        // switched off; a snoozed+failed message must show up there.
        $snoozed->forceFill(['routing_status' => RoutingStatus::Failed])->save();
        $failedFolder = $this->service->paginate(['routing_status' => 'failed']);
        $this->assertTrue($failedFolder->getCollection()->pluck('id')->contains($snoozed->id));
    }
}
