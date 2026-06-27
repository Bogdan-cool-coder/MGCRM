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
 * Unit tests for ActivityService::openTasksCountForDeals — the aggregated
 * "+N по сделкам" signal on the contact KPI (open task-like activities across a
 * set of deal ids, visibility-scoped, single query).
 *
 * Mirrors openTasksCountForContact but keyed on target_type=deal AND
 * target_id IN $dealIds, summed over the whole set.
 */
class OpenTasksCountForDealsTest extends TestCase
{
    use RefreshDatabase;

    private function deal(User $owner): Deal
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
        ]);
    }

    public function test_counts_open_task_like_activities_across_the_set(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]); // All scope
        $dealA = $this->deal($admin);
        $dealB = $this->deal($admin);

        // 2 open tasks on A, 1 on B → 3 across the set.
        Activity::factory()->task()->forDeal($dealA)->create();
        Activity::factory()->call()->forDeal($dealA)->create();
        Activity::factory()->meeting()->forDeal($dealB)->create();

        $service = app(ActivityService::class);

        $this->assertSame(3, $service->openTasksCountForDeals([$dealA->id, $dealB->id], $admin));
    }

    public function test_done_and_rejected_are_excluded(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->deal($admin);

        // 1 open + 1 done + 1 rejected → only the open one counts.
        Activity::factory()->task()->forDeal($deal)->create(['status' => ActivityStatus::New->value, 'is_closed' => false]);
        Activity::factory()->task()->forDeal($deal)->create(['status' => ActivityStatus::Done->value, 'is_closed' => true]);
        Activity::factory()->task()->forDeal($deal)->create(['status' => ActivityStatus::Rejected->value, 'is_closed' => true]);

        $service = app(ActivityService::class);

        $this->assertSame(1, $service->openTasksCountForDeals([$deal->id], $admin));
    }

    public function test_note_does_not_count(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->deal($admin);

        Activity::factory()->note()->forDeal($deal)->create();

        $service = app(ActivityService::class);

        $this->assertSame(0, $service->openTasksCountForDeals([$deal->id], $admin));
    }

    public function test_deals_outside_the_id_set_are_ignored(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $inSet = $this->deal($admin);
        $outside = $this->deal($admin);

        Activity::factory()->task()->forDeal($inSet)->create();
        Activity::factory()->task()->forDeal($outside)->create();

        $service = app(ActivityService::class);

        // Only the in-set deal's task is counted.
        $this->assertSame(1, $service->openTasksCountForDeals([$inSet->id], $admin));
    }

    public function test_empty_ids_short_circuit_to_zero(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $service = app(ActivityService::class);

        $this->assertSame(0, $service->openTasksCountForDeals([], $admin));
    }

    public function test_respects_visibility_scope(): void
    {
        // Own-scope manager: only sees activities where they are responsible or
        // creator. A task on a deal handled by someone else (foreign responsible
        // AND foreign creator) must not be counted.
        $manager = User::factory()->create(['role' => Role::Manager]); // Own scope
        $other = User::factory()->create(['role' => Role::Manager]);

        $deal = $this->deal($manager);

        // Visible: the manager is responsible.
        Activity::factory()->task()->forDeal($deal)->responsibleOf($manager)->create();
        // Invisible: foreign responsible + foreign creator (factory default makes
        // a brand-new creator; pin both to $other so neither matches $manager).
        Activity::factory()->task()->forDeal($deal)->responsibleOf($other)->createdByUser($other)->create();

        $service = app(ActivityService::class);

        // The manager only counts the one task they are responsible for.
        $this->assertSame(1, $service->openTasksCountForDeals([$deal->id], $manager));

        // An All-scope admin counts both.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->assertSame(2, $service->openTasksCountForDeals([$deal->id], $admin));
    }
}
