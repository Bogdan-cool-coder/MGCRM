<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ActivityService::countDealsWithoutTasks — the public contract
 * consumed by the S1.7 dashboard "deals without tasks" widget (BQ1).
 *
 * A deal counts when it is OPEN (stage not won/lost), lives in the pipeline,
 * is inside the user's visibility scope, and has NO open NEXT task targeting it.
 * "No next task" is single-sourced on the Deal::nextTask relation (#10) — the
 * same predicate the deep-linked only_no_task list uses (whereDoesntHave
 * ('nextTask')): task-like kind, is_closed = false, status != Done, due_at NOT
 * NULL. The count and the list it links to therefore always agree.
 */
class DealsWithoutTasksTest extends TestCase
{
    use RefreshDatabase;

    private function pipeline(): Pipeline
    {
        return Pipeline::factory()->create();
    }

    private function openStage(Pipeline $pipeline): PipelineStage
    {
        return PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 1,
        ]);
    }

    private function dealFor(User $owner, Pipeline $pipeline, PipelineStage $stage): Deal
    {
        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
        ]);
    }

    private function openTaskOn(Deal $deal): Activity
    {
        return Activity::factory()->task()->forDeal($deal)->create(['is_closed' => false]);
    }

    public function test_counts_open_deals_with_no_open_task(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]); // All scope
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        // 3 deals, none of which have any activity → all 3 count.
        $this->dealFor($admin, $pipeline, $stage);
        $this->dealFor($admin, $pipeline, $stage);
        $this->dealFor($admin, $pipeline, $stage);

        $service = app(ActivityService::class);

        $this->assertSame(3, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_deal_with_an_open_task_is_excluded(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $withTask = $this->dealFor($admin, $pipeline, $stage);
        $this->openTaskOn($withTask);

        $withoutTask = $this->dealFor($admin, $pipeline, $stage);

        $service = app(ActivityService::class);

        // Only the deal without a task is counted.
        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
        $this->assertNotNull($withoutTask->id);
    }

    public function test_closed_task_does_not_cover_the_deal(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $deal = $this->dealFor($admin, $pipeline, $stage);
        // The only task on the deal is closed → the deal is still "without tasks".
        Activity::factory()->task()->forDeal($deal)->create(['is_closed' => true]);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_note_does_not_count_as_a_task(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $deal = $this->dealFor($admin, $pipeline, $stage);
        // A note is not a task-like kind → the deal remains "without tasks".
        Activity::factory()->note()->forDeal($deal)->create(['is_closed' => false]);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_call_and_meeting_count_as_tasks(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $withCall = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->call()->forDeal($withCall)->create(['is_closed' => false]);

        $withMeeting = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->meeting()->forDeal($withMeeting)->create(['is_closed' => false]);

        $bare = $this->dealFor($admin, $pipeline, $stage);

        $service = app(ActivityService::class);

        // Only $bare is uncovered; call and meeting cover their deals.
        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
        $this->assertNotNull($bare->id);
    }

    public function test_won_and_lost_deals_are_excluded(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();

        $openStage = $this->openStage($pipeline);
        $wonStage = PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);
        $lostStage = PipelineStage::factory()->lost()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 3]);

        // 1 open deal without task → counts.
        $this->dealFor($admin, $pipeline, $openStage);
        // Won / lost deals are closed → never counted, even without any task.
        $this->dealFor($admin, $pipeline, $wonStage);
        $this->dealFor($admin, $pipeline, $lostStage);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_other_pipeline_deals_are_excluded(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);
        $this->dealFor($admin, $pipeline, $stage);

        $otherPipeline = $this->pipeline();
        $otherStage = $this->openStage($otherPipeline);
        $this->dealFor($admin, $otherPipeline, $otherStage);

        $service = app(ActivityService::class);

        // Only the requested pipeline is counted.
        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_manager_only_sees_own_deals(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]); // Own scope
        $other = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        // 2 own deals without tasks + 3 foreign deals without tasks.
        $this->dealFor($manager, $pipeline, $stage);
        $this->dealFor($manager, $pipeline, $stage);
        $this->dealFor($other, $pipeline, $stage);
        $this->dealFor($other, $pipeline, $stage);
        $this->dealFor($other, $pipeline, $stage);

        $service = app(ActivityService::class);

        // The manager only sees their own 2.
        $this->assertSame(2, $service->countDealsWithoutTasks($pipeline->id, $manager));
    }

    public function test_director_sees_all_deals(): void
    {
        $director = User::factory()->create(['role' => Role::Director]); // All scope
        $m1 = User::factory()->create(['role' => Role::Manager]);
        $m2 = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $this->dealFor($m1, $pipeline, $stage);
        $this->dealFor($m2, $pipeline, $stage);
        $this->dealFor($director, $pipeline, $stage);

        $service = app(ActivityService::class);

        // Director sees every uncovered open deal regardless of owner.
        $this->assertSame(3, $service->countDealsWithoutTasks($pipeline->id, $director));
    }

    public function test_empty_pipeline_returns_zero(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();

        $service = app(ActivityService::class);

        $this->assertSame(0, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    // ---- #10: the widget count equals the list it deep-links to ----

    public function test_done_but_unclosed_task_does_not_cover_the_deal(): void
    {
        // A task with status=Done but is_closed=false is NOT an open next task
        // (nextTask requires status != Done). The OLD count predicate only checked
        // is_closed and would have wrongly treated this deal as "covered", drifting
        // from the list. The deal must still count as "without tasks".
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $deal = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->task()->forDeal($deal)->create([
            'is_closed' => false,
            'status' => ActivityStatus::Done->value,
        ]);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_task_without_due_at_does_not_cover_the_deal(): void
    {
        // A task-like activity with no due_at is not a next task (nextTask requires
        // due_at IS NOT NULL). The OLD predicate ignored due_at and would have
        // counted this deal as covered. The list (whereDoesntHave('nextTask'))
        // counts it as "without tasks" — the widget must agree.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        $deal = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->task()->forDeal($deal)->create([
            'is_closed' => false,
            'due_at' => null,
        ]);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->countDealsWithoutTasks($pipeline->id, $admin));
    }

    public function test_count_matches_the_deep_linked_list_query(): void
    {
        // The badge count must equal the list the widget deep-links to. The list
        // filters open deals by whereDoesntHave('nextTask') (DealService
        // only_no_task); the count single-sources the same predicate (#10). Seed a
        // mix of covering / non-covering edge cases and assert the two agree.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->pipeline();
        $stage = $this->openStage($pipeline);

        // Counts (no open next task):
        $this->dealFor($admin, $pipeline, $stage);                       // bare
        $covered = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->task()->forDeal($covered)->create([         // closed → counts
            'is_closed' => true,
        ]);
        $dueLess = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->task()->forDeal($dueLess)->create([         // no due_at → counts
            'is_closed' => false, 'due_at' => null,
        ]);

        // Does NOT count (has an open next task):
        $withNext = $this->dealFor($admin, $pipeline, $stage);
        Activity::factory()->task()->forDeal($withNext)->create([
            'is_closed' => false, 'due_at' => now()->addDay(),
        ]);

        $service = app(ActivityService::class);
        $count = $service->countDealsWithoutTasks($pipeline->id, $admin);

        // The list predicate, applied directly to the same open-deal universe.
        $listTotal = Deal::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereHas('stage', fn ($q) => $q->where('is_won', false)->where('is_lost', false))
            ->whereDoesntHave('nextTask')
            ->count();

        $this->assertSame($listTotal, $count, 'badge count must equal the deep-linked list query');
        $this->assertSame(3, $count);
    }
}
