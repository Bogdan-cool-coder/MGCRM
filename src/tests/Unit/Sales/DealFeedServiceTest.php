<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Services\DealFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DealFeedService
    {
        return new DealFeedService;
    }

    private function stage(Deal $deal, ?string $at = null): DealStageHistory
    {
        return DealStageHistory::query()->create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $deal->stage_id,
            'user_id' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    private function audit(Deal $deal, ?string $at = null): void
    {
        DealAudit::query()->insert([
            'deal_id' => $deal->id,
            'user_id' => null,
            'field' => 'title',
            'old_value' => 'A',
            'new_value' => 'B',
            'created_at' => $at ?? now(),
        ]);
    }

    public function test_normalises_each_source_into_uniform_shape(): void
    {
        $deal = Deal::factory()->create();
        $actor = User::factory()->create(['full_name' => 'Jane Doe']);

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->createdByUser($actor)->create(['title' => 'Call client']);
        $this->audit($deal);

        $result = $this->service()->feed($deal);

        $this->assertCount(3, $result['data']);

        foreach ($result['data'] as $event) {
            $this->assertArrayHasKey('id', $event);
            $this->assertArrayHasKey('type', $event);
            $this->assertArrayHasKey('occurred_at', $event);
            $this->assertArrayHasKey('actor', $event);
            $this->assertArrayHasKey('payload', $event);
        }

        $types = array_column($result['data'], 'type');
        sort($types);
        $this->assertSame(['activity', 'field_change', 'stage_change'], $types);
    }

    public function test_composite_ids_are_prefixed_per_source(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->create();
        $this->audit($deal);

        $ids = array_column($this->service()->feed($deal)['data'], 'id');

        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'stage_')));
        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'activity_')));
        $this->assertTrue(collect($ids)->contains(fn (string $id): bool => str_starts_with($id, 'audit_')));
    }

    public function test_events_are_sorted_by_occurred_at_descending(): void
    {
        $deal = Deal::factory()->create();

        // Oldest → newest across all three sources, intentionally interleaved.
        $this->audit($deal, (string) now()->subDays(3));
        Activity::factory()->forDeal($deal)->create(['created_at' => now()->subDay()]);
        $this->stage($deal, (string) now()->subDays(2));
        Activity::factory()->forDeal($deal)->create(['created_at' => now()]);

        $events = $this->service()->feed($deal)['data'];

        $occurred = array_column($events, 'occurred_at');
        $sorted = $occurred;
        rsort($sorted);

        $this->assertSame($sorted, $occurred);
    }

    public function test_filter_by_type_returns_only_requested_sources(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        Activity::factory()->forDeal($deal)->create();
        $this->audit($deal);

        $result = $this->service()->feed($deal, ['types' => ['stage_change']]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('stage_change', $result['data'][0]['type']);
    }

    public function test_invalid_type_filter_is_ignored(): void
    {
        $deal = Deal::factory()->create();

        $this->stage($deal);
        $this->audit($deal);

        // Only an unknown type → treated as "no filter" → all events returned.
        $result = $this->service()->feed($deal, ['types' => ['nonsense']]);

        $this->assertSame(2, $result['meta']['total']);
    }

    public function test_pagination_slices_via_for_page_and_reports_total(): void
    {
        $deal = Deal::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->audit($deal, (string) now()->subMinutes($i));
        }

        $page1 = $this->service()->feed($deal, [], 1, 2);
        $page2 = $this->service()->feed($deal, [], 2, 2);
        $page3 = $this->service()->feed($deal, [], 3, 2);

        $this->assertSame(5, $page1['meta']['total']);
        $this->assertSame(2, $page1['meta']['per_page']);
        $this->assertSame(1, $page1['meta']['current_page']);

        $this->assertCount(2, $page1['data']);
        $this->assertCount(2, $page2['data']);
        $this->assertCount(1, $page3['data']);

        // No id appears on more than one page.
        $allIds = array_merge(
            array_column($page1['data'], 'id'),
            array_column($page2['data'], 'id'),
            array_column($page3['data'], 'id'),
        );
        $this->assertCount(5, array_unique($allIds));
    }

    public function test_feed_is_scoped_to_the_deal(): void
    {
        $deal = Deal::factory()->create();
        $other = Deal::factory()->create();

        $this->audit($deal);
        $this->audit($other);
        Activity::factory()->forDeal($other)->create();

        $result = $this->service()->feed($deal);

        $this->assertSame(1, $result['meta']['total']);
    }
}
