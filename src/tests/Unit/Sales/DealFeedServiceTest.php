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

    // ---- F27: bounded-fetch perf optimisation must be byte-identical ----

    /**
     * Seeds N events of EACH source with strictly descending, collision-free
     * timestamps, so the global chronological order across all three sources is
     * fully deterministic (no created_at ties between rows).
     *
     * @return Deal the deal carrying the seeded feed
     */
    private function seedInterleavedFeed(Deal $deal, int $perSource): Deal
    {
        $base = now()->subYear();

        for ($i = 0; $i < $perSource; $i++) {
            // Spread the three sources across distinct minute offsets so no two
            // rows share a timestamp — the same property that lets the bounded
            // fetch reproduce the page exactly.
            $this->stage($deal, (string) $base->copy()->addMinutes($i * 3));
            Activity::factory()->forDeal($deal)->create([
                'created_at' => $base->copy()->addMinutes($i * 3 + 1),
            ]);
            $this->audit($deal, (string) $base->copy()->addMinutes($i * 3 + 2));
        }

        return $deal;
    }

    /**
     * Golden reference: reproduces the PRE-optimisation behaviour — load the
     * newest min(count, 500) of each source, merge, sort desc, forPage — with
     * NO bounded fetch. The optimised service must return identical data/meta.
     *
     * @return array{ids: array<int, string>, total: int}
     */
    private function referencePage(Deal $deal, int $page, int $perPage): array
    {
        $cap = 500;

        $stage = DealStageHistory::query()->where('deal_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (DealStageHistory $r): array => [
                'id' => "stage_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);
        $activity = Activity::query()
            ->where('target_type', \App\Domain\Activity\Enums\ActivityTargetType::Deal->value)
            ->where('target_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (Activity $r): array => [
                'id' => "activity_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);
        $audit = DealAudit::query()->where('deal_id', $deal->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            ->map(fn (DealAudit $r): array => [
                'id' => "audit_{$r->id}", 'at' => $r->created_at?->toIso8601String(),
            ]);

        $merged = $stage->merge($activity)->merge($audit)
            ->sortByDesc(fn (array $e): string => $e['at'] ?? '')
            ->values();

        return [
            'ids' => $merged->forPage($page, $perPage)->pluck('id')->values()->all(),
            'total' => $merged->count(),
        ];
    }

    public function test_bounded_fetch_is_identical_to_full_merge_on_first_pages(): void
    {
        // 40 of each source = 120 events, well over the 30-row page so the
        // bounded fetch (offset+perPage) genuinely loads fewer than the full set.
        $deal = $this->seedInterleavedFeed(Deal::factory()->create(), 40);

        foreach ([1, 2, 3] as $page) {
            $ref = $this->referencePage($deal, $page, 30);
            $actual = $this->service()->feed($deal, [], $page, 30);

            $this->assertSame(
                $ref['ids'],
                array_column($actual['data'], 'id'),
                "page {$page}: ids/order must match the full-merge reference",
            );
            $this->assertSame(120, $actual['meta']['total'], "page {$page}: total parity");
            $this->assertSame($ref['total'], $actual['meta']['total'], "page {$page}: total vs reference");
        }
    }

    public function test_bounded_fetch_preserves_status_and_every_field(): void
    {
        $deal = $this->seedInterleavedFeed(Deal::factory()->create(), 40);

        $first = $this->service()->feed($deal, [], 1, 30)['data'][0];

        // Full shape contract (incl. the Phase-1 `status` on activity payloads).
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('occurred_at', $first);
        $this->assertArrayHasKey('actor', $first);
        $this->assertArrayHasKey('payload', $first);

        $activity = collect($this->service()->feed($deal, [], 1, 90)['data'])
            ->firstWhere('type', 'activity');
        $this->assertNotNull($activity);
        $this->assertArrayHasKey('status', $activity['payload']);
        $this->assertArrayHasKey('is_closed', $activity['payload']);
        $this->assertArrayHasKey('kind', $activity['payload']);
    }

    public function test_deep_page_truncates_at_the_500_ceiling_like_before(): void
    {
        // 600 audit rows: only the newest 500 survive the per-source ceiling,
        // identical to the previous flat-500 behaviour. total must clamp to 500
        // and a page beyond index 500 must be empty.
        $deal = Deal::factory()->create();

        $base = now()->subYear();
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [
                'deal_id' => $deal->id,
                'user_id' => null,
                'field' => 'title',
                'old_value' => 'A',
                'new_value' => 'B',
                'created_at' => $base->copy()->addMinutes($i),
            ];
        }
        DealAudit::query()->insert($rows);

        // total is clamped to the ceiling (sum of min(count, 500)).
        $this->assertSame(500, $this->service()->feed($deal, [], 1, 30)['meta']['total']);

        // The 500 newest survive: page covering indices 480..510 returns exactly
        // the 20 rows up to the ceiling, none beyond it.
        $tail = $this->service()->feed($deal, [], 17, 30); // offset 480
        $this->assertCount(20, $tail['data']);

        // A page entirely past the ceiling is empty — deep-pagination truncation.
        $beyond = $this->service()->feed($deal, [], 18, 30); // offset 510 > 500
        $this->assertCount(0, $beyond['data']);
    }
}
