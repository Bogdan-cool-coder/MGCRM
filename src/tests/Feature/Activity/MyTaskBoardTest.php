<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Personal task board (Сделки — ТЗ §4): GET /api/activities/my-board groups the
 * current user's open task-like activities into urgency buckets.
 */
class MyTaskBoardTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_my_board_buckets_tasks_by_urgency(): void
    {
        $manager = $this->manager();

        // overdue
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->overdue()->create();
        // today (noon)
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);
        // tomorrow (noon)
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDay()->setTime(12, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $res = $this->getJson('/api/activities/my-board')->assertOk();

        $res->assertJsonCount(1, 'data.overdue')
            ->assertJsonCount(1, 'data.today')
            ->assertJsonCount(1, 'data.tomorrow');

        // All five buckets are always present (frontend renders fixed columns).
        foreach (['overdue', 'today', 'tomorrow', 'this_week', 'next_week'] as $bucket) {
            $res->assertJsonStructure(['data' => [$bucket]]);
        }
    }

    public function test_my_board_scopes_to_current_user(): void
    {
        $manager = $this->manager();
        $other = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(10, 0)]);
        Activity::factory()->responsibleOf($other)->createdByUser($other)
            ->create(['due_at' => now()->setTime(10, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today');
    }

    public function test_my_board_excludes_done_and_notes(): void
    {
        $manager = $this->manager();

        // done → excluded
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(9, 0), 'status' => ActivityStatus::Done->value, 'completed_at' => now()]);
        // note → excluded (not task-like)
        Activity::factory()->note()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(9, 0)]);
        // open task → included
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(11, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today');
    }

    public function test_my_board_includes_follow_up_kind(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['kind' => ActivityType::FollowUp->value, 'due_at' => now()->setTime(13, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.kind', ActivityType::FollowUp->value);
    }

    public function test_my_board_card_carries_linked_deal_title(): void
    {
        $manager = $this->manager();
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'title' => 'Ромашка ООО — внедрение',
        ]);
        Activity::factory()->call()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(15, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonPath('data.today.0.deal.id', $deal->id)
            ->assertJsonPath('data.today.0.deal.title', 'Ромашка ООО — внедрение');
    }

    public function test_my_board_filters_by_search(): void
    {
        $manager = $this->manager();

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Позвонить в Альфа', 'due_at' => now()->setTime(10, 0)]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Встреча с Бета', 'due_at' => now()->setTime(11, 0)]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board?q=Альфа')
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'Позвонить в Альфа');
    }

    public function test_admin_role_can_access_my_board(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonStructure(['data' => ['overdue', 'today', 'tomorrow', 'this_week', 'next_week']]);
    }

    public function test_my_board_day_buckets_use_operational_timezone(): void
    {
        // MINOR-8: at 21:00 UTC it is already 01:00 of the NEXT day in Asia/Dubai,
        // so the Dubai "today" is the 16th while the UTC clock still reads the 15th.
        // A task due 10:00 Dubai on the 16th (= 06:00 UTC) must land in "today" for
        // the Dubai team. With a UTC-anchored boundary it would (wrongly) fall into
        // "tomorrow" — this test fails on the old UTC math and passes on the new
        // operational-timezone math.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-03-15 21:00:00', 'UTC')); // 01:00 Dubai, 16th

        $manager = $this->manager();

        // due_at stored as a real UTC instant (as the SPA sends via toISOString):
        // 06:00 UTC on the 16th = 10:00 Dubai on the 16th = the Dubai "today".
        $dueToday = \Carbon\Carbon::parse('2026-03-16 06:00:00', 'UTC');
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => $dueToday]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonCount(0, 'data.tomorrow')
            ->assertJsonCount(0, 'data.overdue');

        \Carbon\Carbon::setTestNow();
    }
}
