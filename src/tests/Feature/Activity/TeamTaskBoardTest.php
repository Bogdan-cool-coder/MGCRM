<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Team task board (M4/M5): GET /api/activities/team-board returns the SAME urgency
 * buckets as my-board, but scoped to the AUTHENTICATED manager's department subtree
 * — so a director/manager sees the open tasks of every user under them, not just
 * their own. Gated to admin/director/manager; department inferred from the caller.
 */
class TeamTaskBoardTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same deterministic operational mid-day anchor as MyTaskBoardTest: a Monday
        // (2026-03-16) at 08:00 UTC keeps every now()->setTime(9..15) due_at safely
        // inside the Dubai "today" window so urgency bucketing is stable regardless
        // of the wall-clock the suite runs at.
        Carbon::setTestNow(Carbon::parse('2026-03-16 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An open, task-like activity due today at noon, denormalised to $owner's
     * department (mirrors how ActivityService::create stamps department_id).
     */
    private function taskFor(User $owner, string $title = 'Task'): Activity
    {
        return Activity::factory()->responsibleOf($owner)->createdByUser($owner)->create([
            'title' => $title,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $owner->department_id,
        ]);
    }

    public function test_director_sees_department_managers_tasks_bucketed(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $managerA = $this->manager($dept->id);
        $managerB = $this->manager($dept->id);

        $this->taskFor($managerA, 'A — позвонить');
        $this->taskFor($managerB, 'B — встреча');
        // A task in ANOTHER department must NOT appear.
        $otherDept = Department::create(['name' => 'Support']);
        $this->taskFor($this->manager($otherDept->id), 'Foreign');

        Sanctum::actingAs($director, ['*']);

        $res = $this->getJson('/api/activities/team-board')->assertOk();

        // Both team managers' tasks land in the today bucket; the foreign one is gone.
        $res->assertJsonCount(2, 'data.today');

        // All six buckets are always present for fixed-column rendering.
        $res->assertJsonStructure([
            'data' => ['overdue', 'today', 'tomorrow', 'this_week', 'next_week', 'later'],
        ]);
    }

    public function test_director_sees_child_department_tasks_via_subtree(): void
    {
        // Subtree walk: a director over "Sales" sees tasks owned by a manager in a
        // CHILD department ("Sales North") too.
        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $parent->id]);

        $this->taskFor($this->manager($child->id), 'Child dept task');

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today');
    }

    public function test_responsible_id_narrows_to_one_manager(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $managerA = $this->manager($dept->id);
        $managerB = $this->manager($dept->id);

        $this->taskFor($managerA, 'A task');
        $this->taskFor($managerB, 'B task');

        Sanctum::actingAs($director, ['*']);

        $res = $this->getJson('/api/activities/team-board?responsible_id='.$managerA->id)
            ->assertOk();

        $res->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'A task');
    }

    public function test_q_filters_team_board_by_title(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $managerA = $this->manager($dept->id);

        $this->taskFor($managerA, 'Позвонить в Альфа');
        $this->taskFor($managerA, 'Встреча с Бета');

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board?q=Альфа')
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'Позвонить в Альфа');
    }

    public function test_tasks_in_other_department_are_excluded(): void
    {
        $mine = Department::create(['name' => 'Sales']);
        $theirs = Department::create(['name' => 'Support']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $mine->id]);

        // Only a foreign-department task exists → the director sees an empty board.
        $this->taskFor($this->manager($theirs->id), 'Foreign task');

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonCount(0, 'data.today');
    }

    public function test_manager_sees_own_department_but_not_a_sibling_department(): void
    {
        // A manager is scoped to their own department subtree, exactly like a director.
        $mine = Department::create(['name' => 'Sales']);
        $sibling = Department::create(['name' => 'Sales West']);
        $manager = $this->manager($mine->id);
        $peer = $this->manager($mine->id);

        $this->taskFor($peer, 'Peer task');
        $this->taskFor($this->manager($sibling->id), 'Sibling task');

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.today');
    }

    public function test_board_buckets_by_urgency_and_excludes_closed_and_notes(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $manager = $this->manager($dept->id);

        // overdue
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->overdue()->create(['department_id' => $dept->id]);
        // today (noon)
        $this->taskFor($manager, 'Today task');
        // tomorrow (noon)
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDay()->setTime(12, 0), 'department_id' => $dept->id]);
        // done → excluded
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create([
                'due_at' => now()->setTime(9, 0),
                'status' => ActivityStatus::Done->value,
                'is_closed' => true,
                'completed_at' => now(),
                'department_id' => $dept->id,
            ]);
        // note → excluded (not task-like)
        Activity::factory()->note()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(9, 0), 'department_id' => $dept->id]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonCount(1, 'data.overdue')
            ->assertJsonCount(1, 'data.today')
            ->assertJsonCount(1, 'data.tomorrow');
    }

    public function test_admin_without_department_sees_all_task_owners(): void
    {
        // An admin has no department anchor → org-wide supervision: they see tasks
        // across every department (the department filter is intentionally skipped).
        $admin = User::factory()->create(['role' => Role::Admin, 'department_id' => null]);
        $deptX = Department::create(['name' => 'Sales']);
        $deptY = Department::create(['name' => 'Support']);

        $this->taskFor($this->manager($deptX->id), 'X task');
        $this->taskFor($this->manager($deptY->id), 'Y task');

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonCount(2, 'data.today');
    }

    public function test_manager_role_can_access_team_board(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $manager = $this->manager($dept->id);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/team-board')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['overdue', 'today', 'tomorrow', 'this_week', 'next_week', 'later'],
            ]);
    }

    public function test_non_manager_role_is_forbidden(): void
    {
        // accountant is not a team-management audience → 403 even with a valid token.
        $accountant = User::factory()->create(['role' => Role::Accountant]);

        Sanctum::actingAs($accountant, ['*']);

        $this->getJson('/api/activities/team-board')->assertForbidden();
    }

    public function test_director_with_empty_department_gets_empty_buckets(): void
    {
        // A departmentless director/manager has no subtree anchor (→ [-1]) so the
        // board is empty rather than leaking everything (fail-closed).
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => null]);
        $this->taskFor($this->manager(Department::create(['name' => 'Sales'])->id), 'Someone task');

        Sanctum::actingAs($director, ['*']);

        $res = $this->getJson('/api/activities/team-board')->assertOk();

        foreach (['overdue', 'today', 'tomorrow', 'this_week', 'next_week', 'later'] as $bucket) {
            $res->assertJsonCount(0, 'data.'.$bucket);
        }
    }
}
