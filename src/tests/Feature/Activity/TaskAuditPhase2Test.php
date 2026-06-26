<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityConfigService;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-2 task-management audit cleanup (2026-06-26):
 *   E16 — every activity gate (view/update/delete/complete/reopen/changeStatus)
 *         shares ONE ownership model (own/responsible/creator + department subtree
 *         + All). Previously delete and the status gates dropped the department
 *         branch, so a department manager could EDIT a subordinate's task but not
 *         complete or delete it — an incoherent inversion.
 *   E17 — assertResponsibleAssignable branches on the actor's resolved scope:
 *         Own → self only, Department → subtree, All → anyone. It no longer widens
 *         an Own-scope actor to a department subtree they cannot read.
 */
class TaskAuditPhase2Test extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    /**
     * Grant a role Department scope through the admin-editable matrix (the
     * realistic path — no role resolves to Department by default).
     */
    private function grantDepartmentScope(Role $role): void
    {
        app(VisibilityConfigService::class)->update([$role->value => VisibilityScope::Department]);
    }

    // ---- E16: coherent ownership — department manager can complete AND delete ----

    public function test_department_manager_can_complete_subordinate_task(): void
    {
        $this->grantDepartmentScope(Role::Manager);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $deptManager = $this->manager($parent->id); // Department scope, parent dept
        $subordinate = $this->manager($child->id);

        // A standalone (target-less) task the subordinate owns, in the child dept.
        $task = Activity::factory()->task()
            ->responsibleOf($subordinate)->createdByUser($subordinate)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        Sanctum::actingAs($deptManager, ['*']);

        // Before the E16 fix, complete dropped the department branch → 403.
        $this->postJson("/api/activities/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_department_manager_can_delete_subordinate_task(): void
    {
        $this->grantDepartmentScope(Role::Manager);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $deptManager = $this->manager($parent->id);
        $subordinate = $this->manager($child->id);

        $task = Activity::factory()->task()
            ->responsibleOf($subordinate)->createdByUser($subordinate)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        Sanctum::actingAs($deptManager, ['*']);

        // Before the E16 fix, delete allowed only creator + All → 403.
        $this->deleteJson("/api/activities/{$task->id}")->assertNoContent();
        $this->assertDatabaseMissing('activities', ['id' => $task->id]);
    }

    public function test_department_manager_can_reopen_subordinate_task(): void
    {
        $this->grantDepartmentScope(Role::Manager);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $deptManager = $this->manager($parent->id);
        $subordinate = $this->manager($child->id);

        $task = Activity::factory()->task()
            ->responsibleOf($subordinate)->createdByUser($subordinate)
            ->completed($subordinate)->create();

        Sanctum::actingAs($deptManager, ['*']);

        $this->postJson("/api/activities/{$task->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    // ---- E16: an Own-scope user still cannot act on a foreign task ----

    public function test_own_scope_user_cannot_complete_foreign_task(): void
    {
        // Default manager scope is Own — no Department grant here.
        $owner = $this->manager();
        $intruder = $this->manager();

        $task = Activity::factory()->task()
            ->responsibleOf($owner)->createdByUser($owner)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        Sanctum::actingAs($intruder, ['*']);

        $this->postJson("/api/activities/{$task->id}/complete")->assertForbidden();
    }

    public function test_own_scope_user_cannot_delete_foreign_task(): void
    {
        $owner = $this->manager();
        $intruder = $this->manager();

        $task = Activity::factory()->task()
            ->responsibleOf($owner)->createdByUser($owner)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        Sanctum::actingAs($intruder, ['*']);

        $this->deleteJson("/api/activities/{$task->id}")->assertForbidden();
        $this->assertDatabaseHas('activities', ['id' => $task->id]);
    }

    // ---- E17: reassignment allowed-target set matches the actor's read scope ----

    public function test_own_scope_user_cannot_reassign_to_arbitrary_user(): void
    {
        // Default manager = Own scope. The actor sees no one but themselves, so a
        // reassignment to an arbitrary user must be rejected (E17 — Own → self only).
        $owner = $this->manager();
        $stranger = $this->manager();

        $task = Activity::factory()->task()
            ->responsibleOf($owner)->createdByUser($owner)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $this->expectException(ValidationException::class);

        app(ActivityService::class)->update($task, ['responsible_id' => $stranger->id], $owner);
    }

    public function test_own_scope_user_can_reassign_to_self(): void
    {
        // Self-assignment / creating-for-self is always allowed under Own scope.
        $owner = $this->manager();
        $other = $this->manager();

        $task = Activity::factory()->task()
            ->responsibleOf($other)->createdByUser($owner)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $updated = app(ActivityService::class)->update($task, ['responsible_id' => $owner->id], $owner);

        $this->assertSame($owner->id, (int) $updated->responsible_id);
    }

    public function test_department_manager_can_reassign_within_subtree(): void
    {
        $this->grantDepartmentScope(Role::Manager);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $deptManager = $this->manager($parent->id);
        $subordinate = $this->manager($child->id);

        $task = Activity::factory()->task()
            ->responsibleOf($deptManager)->createdByUser($deptManager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        // The subordinate sits in the actor's department subtree → allowed.
        $updated = app(ActivityService::class)->update($task, ['responsible_id' => $subordinate->id], $deptManager);

        $this->assertSame($subordinate->id, (int) $updated->responsible_id);
    }

    public function test_department_manager_cannot_reassign_outside_subtree(): void
    {
        $this->grantDepartmentScope(Role::Manager);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);
        $foreign = Department::create(['name' => 'Marketing']); // not under parent

        $deptManager = $this->manager($parent->id);
        $outsider = $this->manager($foreign->id);

        $task = Activity::factory()->task()
            ->responsibleOf($deptManager)->createdByUser($deptManager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $this->expectException(ValidationException::class);

        app(ActivityService::class)->update($task, ['responsible_id' => $outsider->id], $deptManager);
    }

    public function test_all_scope_user_can_reassign_to_anyone(): void
    {
        // A director (All scope) reassigns freely — even to a user in an unrelated
        // department.
        $director = $this->director();
        $foreign = Department::create(['name' => 'Marketing']);
        $anyone = $this->manager($foreign->id);

        $task = Activity::factory()->task()
            ->responsibleOf($director)->createdByUser($director)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $updated = app(ActivityService::class)->update($task, ['responsible_id' => $anyone->id], $director);

        $this->assertSame($anyone->id, (int) $updated->responsible_id);
    }
}
