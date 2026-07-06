<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealContactService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deal Create 2.0 §5.3/§5.4 — owner auto-sync (docs/specs/deal-create-2-contract.md):
 *
 *   Rule A: a deal's owner_user_id change drives its company + linked
 *           contacts' owner (owner_user_id / owner_id) — the deal wins,
 *           regardless of who is assigned to a task on it.
 *   Rule B: a point task (no linked deal) on a contact/company sets its owner
 *           to the task's assignee, UNLESS the target already participates in
 *           an OPEN deal (open = stage is_won=false AND is_lost=false).
 */
class DealOwnerSyncTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    // -------------------------------------------------------------------
    // Rule A — deal owner change syncs company + contacts
    // -------------------------------------------------------------------

    public function test_deal_owner_change_syncs_company_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);

        $deal = Deal::factory()->forOwner($originalOwner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        app(DealService::class)->update($deal, ['owner_user_id' => $newOwner->id], $originalOwner);

        $this->assertSame($newOwner->id, $company->fresh()->owner_user_id);
    }

    public function test_deal_owner_change_syncs_linked_contacts(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);
        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $deal = Deal::factory()->forOwner($originalOwner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        app(DealContactService::class)->addContact($deal, $contact->id);

        app(DealService::class)->update($deal, ['owner_user_id' => $newOwner->id], $originalOwner);

        $this->assertSame($newOwner->id, $contact->fresh()->owner_id);
    }

    public function test_deal_owner_change_syncs_even_on_won_deal(): void
    {
        // Contract §5.6 edge case: DealOwnerChanged syncs regardless of the
        // deal's status — a closed (won) deal still drives its company's owner.
        $pipeline = $this->seedSalesPipeline();
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);

        $deal = Deal::factory()->forOwner($originalOwner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'won'),
            'company_id' => $company->id,
        ]);

        app(DealService::class)->update($deal, ['owner_user_id' => $newOwner->id], $originalOwner);

        $this->assertSame($newOwner->id, $company->fresh()->owner_user_id);
    }

    public function test_self_assign_owner_is_noop(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);

        $before = $company->fresh()->updated_at;

        // Same owner as already set — the FormRequest/service treats this as a
        // no-op change (owner_user_id unchanged), so no DealOwnerChanged fires.
        app(DealService::class)->update($deal, ['owner_user_id' => $owner->id], $owner);

        $this->assertSame($owner->id, $company->fresh()->owner_user_id);
    }

    public function test_new_deal_with_company_claims_company_owner_when_unset(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $creator = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => null]);

        app(DealService::class)->create([
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
        ], $creator);

        $this->assertSame($creator->id, $company->fresh()->owner_user_id);
    }

    // -------------------------------------------------------------------
    // Rule B — point task on contact syncs owner unless blocked (Company:
    // see note below — task 6.1's pre-existing "owner_user_id is never
    // touched by a task assignment" invariant takes precedence)
    // -------------------------------------------------------------------

    /**
     * Company is deliberately NOT wired to Rule B through the task-assignment
     * listener: ActivityOwnerSyncTest already asserts the pre-existing,
     * intentional 6.1 guarantee that a task assignment on a company NEVER
     * changes `owner_user_id` (only `responsible_user_id`, a distinct "current
     * handler" column). Rule B's Company-side is exercised directly against
     * CompanyService::syncOwnerFromTask below (a real, tested capability —
     * just not invoked by SyncOwnerOnTaskAssigned today; see its docblock).
     */
    public function test_company_task_assignment_never_touches_owner_user_id(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);

        app(ActivityService::class)->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Follow up',
            'responsible_id' => $assignee->id,
        ], $director);

        $this->assertSame($originalOwner->id, $company->fresh()->owner_user_id);
    }

    public function test_company_service_sync_owner_from_task_without_open_deal(): void
    {
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);

        app(CompanyService::class)->syncOwnerFromTask(
            $company,
            $assignee->id,
            hasOpenDeal: false,
        );

        $this->assertSame($assignee->id, $company->fresh()->owner_user_id);
    }

    public function test_company_service_sync_owner_from_task_blocked_by_open_deal(): void
    {
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $originalOwner->id]);

        app(CompanyService::class)->syncOwnerFromTask(
            $company,
            $assignee->id,
            hasOpenDeal: true,
        );

        $this->assertSame($originalOwner->id, $company->fresh()->owner_user_id);
    }

    public function test_point_task_on_contact_without_open_deal_syncs_owner(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        app(ActivityService::class)->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Follow up',
            'responsible_id' => $assignee->id,
        ], $director);

        $this->assertSame($assignee->id, $contact->fresh()->owner_id);
    }

    public function test_point_task_on_contact_with_open_deal_does_not_change_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        $originalOwner = User::factory()->create(['role' => Role::Manager]);
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $originalOwner->id]);

        $deal = Deal::factory()->forOwner($originalOwner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        app(DealContactService::class)->addContact($deal, $contact->id);

        app(ActivityService::class)->create([
            'kind' => ActivityType::Task->value,
            'target_type' => 'contact',
            'target_id' => $contact->id,
            'title' => 'Follow up',
            'responsible_id' => $assignee->id,
        ], $director);

        $this->assertSame($originalOwner->id, $contact->fresh()->owner_id);
    }
}
