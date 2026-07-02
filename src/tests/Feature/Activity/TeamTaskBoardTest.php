<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
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

    public function test_kind_filter_narrows_team_board(): void
    {
        // «Команда» must filter by task kind exactly like «Мои задачи»: a kind=call
        // request keeps only the calls in the subtree, dropping the meeting.
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $manager = $this->manager($dept->id);

        Activity::factory()->call()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Call task', 'due_at' => now()->setTime(12, 0), 'department_id' => $dept->id]);
        Activity::factory()->meeting()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Meeting task', 'due_at' => now()->setTime(12, 0), 'department_id' => $dept->id]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board?kind='.ActivityType::Call->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'Call task');
    }

    public function test_priority_filter_narrows_team_board(): void
    {
        // priority=critical keeps only the critical task in the subtree.
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $manager = $this->manager($dept->id);

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create([
            'title' => 'Critical task',
            'priority' => ActivityPriority::Critical->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create([
            'title' => 'Normal task',
            'priority' => ActivityPriority::Normal->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board?priority='.ActivityPriority::Critical->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'Critical task');
    }

    public function test_status_filter_narrows_team_board(): void
    {
        // status filters on the status COLUMN (new/in_progress/…), narrowing the open
        // board to one workflow state. Both tasks are open, so both would otherwise
        // land in today; status=in_progress must keep only the in-progress one.
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $manager = $this->manager($dept->id);

        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create([
            'title' => 'In progress task',
            'status' => ActivityStatus::InProgress->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create([
            'title' => 'New task',
            'status' => ActivityStatus::New->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board?status='.ActivityStatus::InProgress->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'In progress task');
    }

    public function test_due_range_filter_narrows_team_board(): void
    {
        // due_from/due_to bound the due_at window: a range covering only tomorrow
        // keeps the tomorrow task and drops the one due today.
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $manager = $this->manager($dept->id);

        $this->taskFor($manager, 'Today task');
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create([
            'title' => 'Tomorrow task',
            'due_at' => now()->addDay()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        // Plain 'Y-m-d H:i:s' bounds (no timezone offset) — the due_at column stores
        // UTC and the query compares string-wise, so an offset-free bound avoids any
        // query-string encoding ambiguity around the '+00:00' suffix.
        $dueFrom = now()->addDay()->startOfDay()->format('Y-m-d H:i:s');
        $dueTo = now()->addDay()->endOfDay()->format('Y-m-d H:i:s');

        $this->getJson('/api/activities/team-board?due_from='.urlencode($dueFrom).'&due_to='.urlencode($dueTo))
            ->assertOk()
            ->assertJsonCount(0, 'data.today')
            ->assertJsonCount(1, 'data.tomorrow')
            ->assertJsonPath('data.tomorrow.0.title', 'Tomorrow task');
    }

    public function test_combined_filters_narrow_within_department(): void
    {
        // The «Команда» view must combine kind + priority + responsible_id AND-wise,
        // narrowing to a single card inside the subtree — the same 1:1 filter set as
        // the personal board. Only managerA's critical call should survive; every
        // near-miss (wrong kind, wrong priority, wrong manager) is excluded.
        $dept = Department::create(['name' => 'Sales']);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $dept->id]);
        $managerA = $this->manager($dept->id);
        $managerB = $this->manager($dept->id);

        // The one row that matches every filter.
        Activity::factory()->call()->responsibleOf($managerA)->createdByUser($managerA)->create([
            'title' => 'Target',
            'priority' => ActivityPriority::Critical->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);
        // Same manager + kind, wrong priority.
        Activity::factory()->call()->responsibleOf($managerA)->createdByUser($managerA)->create([
            'title' => 'Wrong priority',
            'priority' => ActivityPriority::Normal->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);
        // Same manager + priority, wrong kind.
        Activity::factory()->meeting()->responsibleOf($managerA)->createdByUser($managerA)->create([
            'title' => 'Wrong kind',
            'priority' => ActivityPriority::Critical->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);
        // Right kind + priority, wrong manager.
        Activity::factory()->call()->responsibleOf($managerB)->createdByUser($managerB)->create([
            'title' => 'Wrong manager',
            'priority' => ActivityPriority::Critical->value,
            'due_at' => now()->setTime(12, 0),
            'department_id' => $dept->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities/team-board?kind='.ActivityType::Call->value
            .'&priority='.ActivityPriority::Critical->value
            .'&responsible_id='.$managerA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.title', 'Target');
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
