<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityPresetsTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

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
}
