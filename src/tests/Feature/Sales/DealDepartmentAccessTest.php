<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M9 — FULL department access for managers.
 *
 * A manager has full CRUD over any deal (and any activity on it) within their
 * department subtree — the same as the owner would — and nothing across other
 * departments. Read and write share the same scope:
 *
 *   READ  (list/view deal, view task)        → own + department subtree.
 *   CREATE (note/task on a visible deal)      → allowed for any deal they can see.
 *   WRITE (update/move/delete deal;           → allowed for any deal/task in their
 *          edit/complete/delete existing task)   department subtree.
 *   CROSS-DEPARTMENT                           → 403 / not listed.
 *
 * (A future per-user restriction layer may narrow an individual manager below full
 * department access; that layer is out of scope here.)
 *
 * Manager scope resolves to Department by default (VisibilityScope::forRole), so
 * these tests need no matrix seeding — they exercise the shipped default.
 */
class DealDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** The Sales department (peers A & B live here). */
    private function salesDept(): Department
    {
        return Department::create(['name' => 'Sales']);
    }

    private function managerIn(Department $dept): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
        ]);
    }

    /** A deal owned by $owner, stamped with the owner's department. */
    private function dealOwnedBy(User $owner): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
    }

    // ---------------------------------------------------------------------
    // READ: a department peer can LIST + VIEW a colleague's deal.
    // ---------------------------------------------------------------------

    public function test_department_peer_can_list_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonFragment(['id' => $dealX->id]);
    }

    public function test_department_peer_can_view_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->getJson("/api/deals/{$dealX->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $dealX->id);
    }

    // ---------------------------------------------------------------------
    // WRITE on the deal: a department peer CAN update/move/delete it.
    // ---------------------------------------------------------------------

    public function test_department_peer_can_update_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->patchJson("/api/deals/{$dealX->id}", ['title' => 'Updated by teammate'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated by teammate');

        $this->assertDatabaseHas('deals', ['id' => $dealX->id, 'title' => 'Updated by teammate']);
    }

    public function test_department_peer_can_move_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);
        $qualify = $dealX->pipeline->stages->firstWhere('code', 'qualify');

        Sanctum::actingAs($managerB, ['*']);

        $this->postJson("/api/deals/{$dealX->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);

        $this->assertDatabaseHas('deals', ['id' => $dealX->id, 'stage_id' => $qualify->id]);
    }

    public function test_department_peer_can_delete_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->deleteJson("/api/deals/{$dealX->id}")->assertNoContent();
        $this->assertSoftDeleted('deals', ['id' => $dealX->id]);
    }

    // ---------------------------------------------------------------------
    // CREATE: a department peer CAN add a note/task to a colleague's deal.
    // ---------------------------------------------------------------------

    public function test_department_peer_can_create_note_on_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Note->value,
            'target_type' => 'deal',
            'target_id' => $dealX->id,
            'title' => 'Heads-up: client called me',
        ])->assertCreated()
            ->assertJsonPath('data.target_id', $dealX->id)
            ->assertJsonPath('data.created_by_id', $managerB->id);
    }

    public function test_department_peer_can_create_task_on_colleague_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerB, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Task->value,
            'target_type' => 'deal',
            'target_id' => $dealX->id,
            'title' => 'Please send the KP',
            'due_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.target_id', $dealX->id)
            ->assertJsonPath('data.created_by_id', $managerB->id);
    }

    // ---------------------------------------------------------------------
    // MUTATE an existing activity: a department peer CAN edit / complete /
    // delete Manager A's own task on a department deal.
    // ---------------------------------------------------------------------

    public function test_department_peer_can_complete_colleague_existing_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        // Manager A's OWN task on the deal.
        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false]);

        Sanctum::actingAs($managerB, ['*']);

        $this->postJson("/api/activities/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_department_peer_can_update_colleague_existing_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false, 'title' => 'A original']);

        Sanctum::actingAs($managerB, ['*']);

        $this->patchJson("/api/activities/{$task->id}", ['title' => 'B edited'])
            ->assertOk()
            ->assertJsonPath('data.title', 'B edited');

        $this->assertDatabaseHas('activities', ['id' => $task->id, 'title' => 'B edited']);
    }

    public function test_department_peer_can_delete_colleague_existing_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false]);

        Sanctum::actingAs($managerB, ['*']);

        $this->deleteJson("/api/activities/{$task->id}")->assertNoContent();
        $this->assertDatabaseMissing('activities', ['id' => $task->id]);
    }

    public function test_department_peer_can_view_colleague_existing_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $managerB = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false]);

        Sanctum::actingAs($managerB, ['*']);

        $this->getJson("/api/activities/{$task->id}")->assertOk();
    }

    // ---------------------------------------------------------------------
    // Cross-department isolation: a Finance manager sees/touches nothing of Sales.
    // ---------------------------------------------------------------------

    public function test_other_department_manager_cannot_view_deal(): void
    {
        $sales = $this->salesDept();
        $finance = Department::create(['name' => 'Finance']);
        $managerA = $this->managerIn($sales);
        $managerC = $this->managerIn($finance);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerC, ['*']);

        $this->getJson("/api/deals/{$dealX->id}")->assertForbidden();
    }

    public function test_other_department_manager_cannot_update_deal(): void
    {
        $sales = $this->salesDept();
        $finance = Department::create(['name' => 'Finance']);
        $managerA = $this->managerIn($sales);
        $managerC = $this->managerIn($finance);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerC, ['*']);

        $this->patchJson("/api/deals/{$dealX->id}", ['title' => 'cross-dept edit'])
            ->assertForbidden();

        $this->assertDatabaseHas('deals', ['id' => $dealX->id, 'title' => $dealX->title]);
    }

    public function test_other_department_manager_cannot_add_activity_to_deal(): void
    {
        // Not visible → cannot even leave a note (target-view gate returns 422).
        $sales = $this->salesDept();
        $finance = Department::create(['name' => 'Finance']);
        $managerA = $this->managerIn($sales);
        $managerC = $this->managerIn($finance);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerC, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Note->value,
            'target_type' => 'deal',
            'target_id' => $dealX->id,
            'title' => 'from another dept',
        ])->assertStatus(422);
    }

    public function test_other_department_manager_does_not_list_deal(): void
    {
        $sales = $this->salesDept();
        $finance = Department::create(['name' => 'Finance']);
        $managerA = $this->managerIn($sales);
        $managerC = $this->managerIn($finance);
        $dealX = $this->dealOwnedBy($managerA);

        Sanctum::actingAs($managerC, ['*']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonMissing(['id' => $dealX->id]);
    }

    // ---------------------------------------------------------------------
    // Director (All scope): sees AND can edit subordinates' deals (unchanged).
    // ---------------------------------------------------------------------

    public function test_director_can_view_and_move_subordinate_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $sales->id]);
        $dealX = $this->dealOwnedBy($managerA);
        $qualify = $dealX->pipeline->stages->firstWhere('code', 'qualify');

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/deals/{$dealX->id}")->assertOk();

        $this->postJson("/api/deals/{$dealX->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);
    }

    public function test_director_can_complete_subordinate_existing_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $sales->id]);
        $dealX = $this->dealOwnedBy($managerA);

        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/activities/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    // ---------------------------------------------------------------------
    // overridePrice stays All-only: a manager (owner OR peer) cannot re-price.
    // ---------------------------------------------------------------------

    public function test_manager_cannot_override_price_but_director_can(): void
    {
        // overridePrice stays All-only (admin/director/lawyer) even under full
        // department CRUD — a manager (owner or peer) cannot re-price a line item.
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $sales->id]);
        $dealX = $this->dealOwnedBy($managerA);

        $this->assertFalse(Gate::forUser($managerA)->allows('overridePrice', $dealX));
        $this->assertTrue(Gate::forUser($director)->allows('overridePrice', $dealX));
    }

    // ---------------------------------------------------------------------
    // Regression: a manager's OWN deal + OWN task stay fully editable.
    // ---------------------------------------------------------------------

    public function test_owner_can_fully_manage_own_deal(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);
        $qualify = $dealX->pipeline->stages->firstWhere('code', 'qualify');

        Sanctum::actingAs($managerA, ['*']);

        $this->getJson("/api/deals/{$dealX->id}")->assertOk();
        $this->patchJson("/api/deals/{$dealX->id}", ['title' => 'Owner edit'])->assertOk();
        $this->postJson("/api/deals/{$dealX->id}/move", ['to_stage_id' => $qualify->id])
            ->assertOk()
            ->assertJsonPath('data.stage_id', $qualify->id);
    }

    public function test_owner_can_complete_own_task(): void
    {
        $sales = $this->salesDept();
        $managerA = $this->managerIn($sales);
        $dealX = $this->dealOwnedBy($managerA);

        $task = Activity::factory()->task()->forDeal($dealX)
            ->responsibleOf($managerA)->createdByUser($managerA)
            ->create(['is_closed' => false]);

        Sanctum::actingAs($managerA, ['*']);

        $this->postJson("/api/activities/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }
}
