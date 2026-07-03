<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/task-matrix (R4, contract §6.7).
 * Plan from plan_targets(metric=tasks_completed, config.activity_kind),
 * fact = completed Activity counts grouped by kind × responsible_id × month.
 */
class TaskMatrixReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'is_active' => true,
            'is_service' => false,
            'department_id' => $department?->id,
        ]);
    }

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true, 'is_service' => false]);
    }

    public function test_completed_meeting_counts_toward_the_meeting_kind_fact(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 3)
            ->tasksCompleted('meeting', 40)->create();

        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-03-10 10:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $this->assertNotNull($group);
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(40, $row['cells']['3']['plan_count']);
        $this->assertSame(1, $row['cells']['3']['fact_count']);
    }

    public function test_only_status_done_with_completed_at_counts_as_completed(): void
    {
        $manager = $this->makeManager();

        Activity::factory()->meeting()->responsibleOf($manager)->create([
            'status' => ActivityStatus::InProgress->value,
            'completed_at' => null,
            'due_at' => '2026-04-05',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(0, $row['cells']['4']['fact_count'], 'an in-progress meeting must not count as completed');
    }

    public function test_different_kind_does_not_bleed_into_another_kinds_fact(): void
    {
        $manager = $this->makeManager();

        Activity::factory()->call()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-05-02 09:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(0, $row['cells']['5']['fact_count'], 'a completed call must not count toward the meeting kind');
    }

    public function test_month_boundary_is_respected(): void
    {
        $manager = $this->makeManager();

        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-01-31 23:59:59',
        ]);
        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-02-01 00:00:01',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(1, $row['cells']['1']['fact_count']);
        $this->assertSame(1, $row['cells']['2']['fact_count']);
    }

    public function test_other_users_completed_activities_are_not_counted_on_this_managers_row(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();

        Activity::factory()->meeting()->responsibleOf($managerB)->completed()->create([
            'completed_at' => '2026-06-10 12:00:00',
        ]);

        $director = $this->makeDirector();
        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $rowA = collect($group['rows'])->firstWhere('scope.id', $managerA->id);
        $rowB = collect($group['rows'])->firstWhere('scope.id', $managerB->id);

        $this->assertSame(0, $rowA['cells']['6']['fact_count']);
        $this->assertSame(1, $rowB['cells']['6']['fact_count']);
    }

    public function test_no_kind_filter_returns_all_task_like_groups(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative')->assertOk();

        $kinds = collect($response->json('groups'))->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['call', 'meeting', 'task', 'follow_up', 'presentation'], $kinds);
    }

    public function test_ftm_sentinel_counts_only_qualified_first_time_meetings(): void
    {
        $manager = $this->makeManager();

        // Qualifies as FTM: meeting + all 3 flags + report url + completed.
        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-07-05 10:00:00',
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.test/report/1',
        ]);

        // A completed meeting that does NOT qualify as FTM (missing report url).
        Activity::factory()->meeting()->responsibleOf($manager)->completed()->create([
            'completed_at' => '2026-07-06 10:00:00',
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => null,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=ftm')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'ftm');
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(1, $row['cells']['7']['fact_count'], 'only the fully-qualified FTM meeting must count');
    }

    public function test_pipeline_filter_restricts_fact_to_deal_linked_activities_in_that_pipeline(): void
    {
        $manager = $this->makeManager();

        $dealInPipeline = Deal::factory()->forOwner($manager)->create();
        $dealOutsidePipeline = Deal::factory()->forOwner($manager)->create();

        Activity::factory()->meeting()->responsibleOf($manager)->forDeal($dealInPipeline)->completed()->create([
            'completed_at' => '2026-08-01 10:00:00',
        ]);
        Activity::factory()->meeting()->responsibleOf($manager)->forDeal($dealOutsidePipeline)->completed()->create([
            'completed_at' => '2026-08-02 10:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson(
            '/api/reports/task-matrix?year=2026&layer=operative&kind=meeting&pipeline_id='.$dealInPipeline->pipeline_id
        )->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $row = collect($group['rows'])->firstWhere('scope.id', $manager->id);

        $this->assertSame(1, $row['cells']['8']['fact_count']);
    }

    public function test_manager_department_scope_excludes_other_department_manager_row(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $managerA = $this->makeManager($deptA);
        $managerB = $this->makeManager($deptB);

        Sanctum::actingAs($managerA, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=meeting')->assertOk();

        $group = collect($response->json('groups'))->firstWhere('kind', 'meeting');
        $ids = collect($group['rows'])->pluck('scope.id')->all();

        $this->assertContains($managerA->id, $ids);
        $this->assertNotContains($managerB->id, $ids);
    }

    public function test_can_edit_reflects_plans_manage_permission(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/task-matrix?year=2026&layer=operative')->assertOk();

        $this->assertFalse($response->json('meta.can_edit'), 'a plain manager must not get plans.manage');
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/reports/task-matrix?year=2026&layer=operative&kind=bogus')->assertStatus(422);
    }
}
