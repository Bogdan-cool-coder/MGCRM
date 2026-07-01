<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityPresetsTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock at a deterministic operational mid-day so preset
        // bucketing (today/this_week) is independent of the wall-clock the suite
        // runs at. Presets anchor "today" to Asia/Dubai (config('salespulse.timezone'));
        // at a late UTC hour it is already the next Dubai day, so a UTC-noon due_at
        // would slip out of "today". 2026-03-16 is a Monday; 08:00 UTC keeps every
        // now()->setTime(9..15) due_at inside the Dubai "today" UTC window and the
        // addDays(2) task inside the Mon–Sun week.
        Carbon::setTestNow(Carbon::parse('2026-03-16 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_my_tasks_preset(): void
    {
        $manager = $this->manager();
        $other = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Activity::factory()->responsibleOf($other)->createdByUser($other)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/my_tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_my_orders_preset(): void
    {
        $manager = $this->manager();
        $assignee = $this->manager();

        Activity::factory()->responsibleOf($assignee)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/my_orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_overdue_preset(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->overdue()->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create(); // future due

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_today_preset(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDays(3)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/today')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_this_week_preset(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDays(2)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDays(20)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/this_week')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_preset_combined_with_filter_narrows_within_the_preset(): void
    {
        // D2: the FilterPanel feeds the same params to the preset endpoint as to the
        // flat list. A kind filter on the "today" tab must narrow WITHIN the preset
        // — both tasks are due today, but only the call survives the kind filter.
        $manager = $this->manager();

        $todayCall = Activity::factory()->call()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(13, 0)]);
        // A call due NEXT week — outside the preset, the filter must not pull it in.
        Activity::factory()->call()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDays(20)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/today?kind[]=call')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $todayCall->id);
    }

    public function test_preset_filter_by_responsible_id_stays_inside_preset(): void
    {
        // A director (All scope) on the "today" preset is scoped to their OWN work
        // (responsible OR creator) by the preset. Filtering by another user's id
        // therefore yields nothing — the preset window still bounds the result.
        $director = $this->director();
        $other = $this->manager();

        Activity::factory()->responsibleOf($director)->createdByUser($director)
            ->create(['due_at' => now()->setTime(12, 0)]);
        Activity::factory()->responsibleOf($other)->createdByUser($other)
            ->create(['due_at' => now()->setTime(12, 0)]);

        Sanctum::actingAs($director, ['*']);

        // Filtering for the OTHER user's id: the today preset is mine-only, so the
        // intersection is empty.
        $this->getJson('/api/activities/presets/today?responsible_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Filtering for my own id keeps my today task.
        $this->getJson('/api/activities/presets/today?responsible_id='.$director->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_counts_by_preset_matches_preset_lists(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->overdue()->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(10, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $counts = $this->getJson('/api/activities/counts-by-preset')->assertOk()->json('data');

        // The badge count must equal the actual preset list length (fix old bug).
        $overdueList = $this->getJson('/api/activities/presets/overdue')->json('data');
        $todayList = $this->getJson('/api/activities/presets/today')->json('data');

        $this->assertSame(count($overdueList), $counts['overdue']);
        $this->assertSame(count($todayList), $counts['today']);
        $this->assertArrayHasKey('my_tasks', $counts);
        $this->assertArrayHasKey('pinned', $counts);
    }

    public function test_preset_response_carries_meta_total(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->count(3)->create();

        Sanctum::actingAs($manager, ['*']);

        // The frontend reads res.meta.total; a meta-less Collection payload would
        // crash every preset tab (BUG-1). Lock the envelope in.
        $this->getJson('/api/activities/presets/my_tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_unknown_preset_returns_422(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/nonsense')
            ->assertStatus(422)->assertJsonValidationErrorFor('preset');
    }

    public function test_my_open_count(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->count(2)->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create(['is_closed' => true]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-open-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    // ---- B6: the «Выполненные» tab — current user's closed tasks ----

    public function test_completed_preset_returns_only_closed_tasks(): void
    {
        $manager = $this->manager();

        // Done, rejected (closed but not done) and a plain is_closed task all count.
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::Rejected->value, 'is_closed' => true]);
        // Two OPEN tasks that must NOT appear.
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->count(2)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/completed')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_completed_preset_is_visibility_scoped_to_current_user(): void
    {
        $manager = $this->manager();
        $other = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        // Another user's completed task — not "my work", must not leak.
        Activity::factory()->responsibleOf($other)->createdByUser($other)->completed($other)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_completed_preset_count_matches_list(): void
    {
        $manager = $this->manager();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->count(3)->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->count(2)->create();

        Sanctum::actingAs($manager, ['*']);

        $counts = $this->getJson('/api/activities/counts-by-preset')->json('data');
        $list = $this->getJson('/api/activities/presets/completed')->json('data');

        $this->assertSame(count($list), $counts['completed']);
        $this->assertSame(3, $counts['completed']);
    }
}
