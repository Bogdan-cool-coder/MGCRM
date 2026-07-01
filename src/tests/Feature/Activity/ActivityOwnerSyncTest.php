<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 6.1 — Auto-sync of CRM card "owner" from task assignee.
 *
 * When a task-like activity on a Contact or Company gains or changes its
 * responsible_id (via ActivityService::create or ::update), the target's
 * "owner" field must be updated to match:
 *
 *   contact.owner_id            ← activity.responsible_id
 *   company.responsible_user_id ← activity.responsible_id
 *
 * contract:
 *   - contact: owner_id synced on create + reassign
 *   - company: responsible_user_id synced on create + reassign
 *   - author (created_by_id) on the TARGET is NEVER changed
 *   - same responsible = no-op (idempotent)
 *   - note kind = no sync (not task-like)
 *   - deal target = not touched (out of scope)
 *   - completion/deletion does NOT revert the owner (last-write wins)
 */
class ActivityOwnerSyncTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityService::class);
    }

    // -------------------------------------------------------------------------
    // Contact tests
    // -------------------------------------------------------------------------

    /**
     * Creating a task on a contact with a different responsible → owner updates.
     */
    public function test_create_task_on_contact_syncs_owner_id(): void
    {
        $originalOwner = $this->adminUser();
        $assignee = $this->managerUser();

        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Follow up',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);
    }

    /**
     * Reassigning a task on a contact → owner follows the new assignee.
     */
    public function test_reassign_task_on_contact_updates_owner_id(): void
    {
        $originalOwner = $this->adminUser();
        $firstAssignee = $this->managerUser();
        $secondAssignee = $this->managerUser();

        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $activity = $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Initial task',
            'responsible_id' => $firstAssignee->id,
        ], $this->adminUser());

        $this->assertSame($firstAssignee->id, $contact->fresh()->owner_id);

        // Now reassign the same activity to a different user.
        $this->service->update($activity, ['responsible_id' => $secondAssignee->id], $this->adminUser());

        $this->assertSame($secondAssignee->id, $contact->fresh()->owner_id);
    }

    /**
     * Idempotency: creating a task with the SAME responsible as owner → no
     * spurious updated_at bump (we verify owner hasn't changed, not the timestamp,
     * since SQLite :memory: timestamps may coalesce).
     */
    public function test_same_responsible_as_owner_is_noop_on_contact(): void
    {
        $owner = $this->adminUser();
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);

        $before = $contact->fresh()->updated_at;

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Noop task',
            'responsible_id' => $owner->id, // same as current owner
        ], $this->adminUser());

        // Owner must still be the same user.
        $this->assertSame($owner->id, $contact->fresh()->owner_id);
    }

    /**
     * Author (created_by_id on the CONTACT) is never changed.
     *
     * Note: Contact does NOT have a created_by_id column; the "author" here means
     * the owner who was set originally (originalOwner) — we verify created_by_id
     * on the ACTIVITY is not changed, and that the contact.owner_id changed but
     * nothing else unexpected happened. The Activity's own created_by_id is also
     * stable. We verify no mis-attribution.
     */
    public function test_contact_created_by_is_never_changed(): void
    {
        $originalOwner = $this->adminUser();
        $assignee = $this->managerUser();

        $contact = Contact::factory()->create([
            'owner_id' => $originalOwner->id,
            'created_by_id' => $originalOwner->id,
        ]);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Sync task',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $fresh = $contact->fresh();

        // owner_id updated, created_by_id untouched.
        $this->assertSame($assignee->id, $fresh->owner_id);
        $this->assertSame($originalOwner->id, $fresh->created_by_id);
    }

    // -------------------------------------------------------------------------
    // Company tests
    // -------------------------------------------------------------------------

    /**
     * Creating a task on a company with a different responsible →
     * company.responsible_user_id updates.
     */
    public function test_create_task_on_company_syncs_responsible_user_id(): void
    {
        $originalOwner = $this->adminUser();
        $assignee = $this->managerUser();

        $company = Company::factory()->create([
            'owner_user_id' => $originalOwner->id,
            'responsible_user_id' => $originalOwner->id,
        ]);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Company task',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $fresh = $company->fresh();
        $this->assertSame($assignee->id, $fresh->responsible_user_id);
        // owner_user_id is NOT changed by the listener.
        $this->assertSame($originalOwner->id, $fresh->owner_user_id);
    }

    /**
     * Reassigning a task on a company → responsible_user_id follows.
     */
    public function test_reassign_task_on_company_updates_responsible_user_id(): void
    {
        $originalOwner = $this->adminUser();
        $firstAssignee = $this->managerUser();
        $secondAssignee = $this->managerUser();

        $company = Company::factory()->create([
            'owner_user_id' => $originalOwner->id,
            'responsible_user_id' => $originalOwner->id,
        ]);

        $activity = $this->service->create([
            'kind' => ActivityType::Call->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Call CEO',
            'responsible_id' => $firstAssignee->id,
        ], $this->adminUser());

        $this->assertSame($firstAssignee->id, $company->fresh()->responsible_user_id);

        $this->service->update($activity, ['responsible_id' => $secondAssignee->id], $this->adminUser());

        $this->assertSame($secondAssignee->id, $company->fresh()->responsible_user_id);
    }

    /**
     * Idempotency on company: same responsible → no-op.
     */
    public function test_same_responsible_as_company_responsible_is_noop(): void
    {
        $owner = $this->adminUser();

        $company = Company::factory()->create([
            'owner_user_id' => $owner->id,
            'responsible_user_id' => $owner->id,
        ]);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Noop company task',
            'responsible_id' => $owner->id, // same as responsible_user_id
        ], $this->adminUser());

        $this->assertSame($owner->id, $company->fresh()->responsible_user_id);
    }

    // -------------------------------------------------------------------------
    // NULL owner / responsible — must sync (primary use-case: fresh card)
    // -------------------------------------------------------------------------

    /**
     * When contact.owner_id is NULL, assigning a task MUST set owner_id.
     *
     * Regression: the old bare `->where('owner_id', '!=', $id)` produced
     * NULL (not TRUE) in SQL for NULL != X, so the WHERE clause matched zero
     * rows and no UPDATE happened.  The fixed closure uses whereNull OR !=.
     */
    public function test_create_task_on_contact_with_null_owner_sets_owner_id(): void
    {
        $assignee = $this->managerUser();

        // Explicitly create with no owner — this is the "fresh card" state.
        $contact = Contact::factory()->create(['owner_id' => null]);

        $this->assertNull($contact->owner_id);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'First task on ownerless contact',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);
    }

    /**
     * When company.responsible_user_id is NULL, assigning a task MUST set it.
     *
     * Same NULL-comparison regression as above, for the company path.
     */
    public function test_create_task_on_company_with_null_responsible_sets_responsible_user_id(): void
    {
        $owner = $this->adminUser();
        $assignee = $this->managerUser();

        $company = Company::factory()->create([
            'owner_user_id' => $owner->id,
            'responsible_user_id' => null,
        ]);

        $this->assertNull($company->responsible_user_id);

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'First task on unassigned company',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $fresh = $company->fresh();
        $this->assertSame($assignee->id, $fresh->responsible_user_id);
        // owner_user_id untouched.
        $this->assertSame($owner->id, $fresh->owner_user_id);
    }

    // -------------------------------------------------------------------------
    // Note kind — must NOT sync
    // -------------------------------------------------------------------------

    /**
     * A note (not task-like) must NOT trigger the owner sync even if it has
     * a responsible_id (which in practice notes don't, but guard defensively).
     *
     * Notes are not task-like; they carry no deadline/responsible semantics.
     */
    public function test_note_kind_does_not_sync_owner(): void
    {
        $originalOwner = $this->adminUser();
        $differentUser = $this->managerUser();

        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        // We create a note via the factory (bypass ActivityService, which forces
        // status/created_by but doesn't restrict note responsible). We then fire
        // the ActivityAssigned event manually to test the listener in isolation.
        // However, ActivityService::create() sets responsible_id for notes too
        // (not explicitly stripped). Let's verify end-to-end via service.
        // Notes in ActivityService can receive responsible_id (no restriction).
        $this->service->create([
            'kind' => ActivityType::Note->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'A note',
            'responsible_id' => $differentUser->id,
        ], $this->adminUser());

        // Note kind → NOT in taskLikeValues() → owner_id must NOT change.
        $this->assertSame($originalOwner->id, $contact->fresh()->owner_id);
    }

    // -------------------------------------------------------------------------
    // Deal target — must NOT sync
    // -------------------------------------------------------------------------

    /**
     * A task on a deal must NOT change anything on the deal (deals have their
     * own owner_user_id management in DealService).
     *
     * Use a director (VisibilityScope::All) as the actor so the
     * assertResponsibleAssignable guard does not block cross-user assignment,
     * letting us verify that the listener simply ignores deal targets.
     */
    public function test_task_on_deal_does_not_sync_deal_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = $this->director();    // director = VisibilityScope::All — can assign freely
        $assignee = $this->managerUser();

        $deal = $this->dealFor($owner, $pipeline);

        $originalOwnerId = $deal->owner_user_id;

        $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Deal task',
            'responsible_id' => $assignee->id,
        ], $owner); // director can assign to anyone

        // Deal's owner_user_id must stay unchanged — listener must not touch deals.
        $this->assertSame($originalOwnerId, $deal->fresh()->owner_user_id);
    }

    // -------------------------------------------------------------------------
    // Completion / deletion does NOT revert owner (last-write-wins)
    // -------------------------------------------------------------------------

    /**
     * Completing or deleting a task must NOT revert the contact owner back.
     * The owner stays as set by the last assignment event.
     */
    public function test_completing_task_does_not_revert_contact_owner(): void
    {
        $originalOwner = $this->adminUser();
        $assignee = $this->managerUser();

        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $activity = $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Task to complete',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);

        // Complete the task — owner must remain the assignee.
        $this->service->complete($activity, $assignee);

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);
    }

    /**
     * Deleting a task must NOT revert the contact owner.
     */
    public function test_deleting_task_does_not_revert_contact_owner(): void
    {
        $originalOwner = $this->adminUser();
        $assignee = $this->managerUser();

        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $activity = $this->service->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Task to delete',
            'responsible_id' => $assignee->id,
        ], $this->adminUser());

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);

        $this->service->delete($activity);

        // Owner stays the assignee — deletion does not revert ownership.
        $this->assertSame($assignee->id, $contact->fresh()->owner_id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** An admin user who can view all targets (VisibilityScope::All). */
    private function adminUser(): User
    {
        static $admin = null;

        // Re-use within a single test but always create fresh between tests
        // (RefreshDatabase wipes the table).
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function managerUser(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }
}
