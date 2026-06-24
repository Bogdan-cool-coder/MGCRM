<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests that company merge re-parents ALL child FK rows to the master company
 * before soft-deleting the duplicate, fixing BLOCKER crm-companies#2.
 *
 * Tables covered: deals, company_requisites, company_channels,
 * company_client_status_log, crm_companies.holding_id,
 * acquisition_channel_history, crm_contact_company_links.
 *
 * Note: documents is not tested here because the documents table requires
 * contract/template setup beyond the scope of a unit merge test.
 */
class CompanyMergeOrphansTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // -----------------------------------------------------------------------
    // Contact-company pivot
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_contact_company_links(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $contact = Contact::factory()->create(['owner_id' => $this->admin->id]);

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $dup->id,
            'is_primary' => false,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Deals
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_deals(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $deal = Deal::factory()->create([
            'company_id' => $dup->id,
            'owner_user_id' => $this->admin->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'company_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Company requisites
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_company_requisites(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $req = CompanyRequisite::factory()->create(['company_id' => $dup->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('company_requisites', [
            'id' => $req->id,
            'company_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Company channels
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_company_channels(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        // Insert directly using the actual column names from the live schema.
        $chanId = DB::table('company_channels')->insertGetId([
            'company_id' => $dup->id,
            'channel_type' => 'phone',
            'value' => '+70000000000',
            'is_primary_for_channel' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('company_channels', [
            'id' => $chanId,
            'company_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Client status log
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_client_status_log(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        // company_client_status_log has no updated_at column.
        $logId = DB::table('company_client_status_log')->insertGetId([
            'company_id' => $dup->id,
            'old_status' => null,
            'new_status' => 'prospect',
            'changed_by' => $this->admin->id,
            'changed_at' => now(),
            'created_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('company_client_status_log', [
            'id' => $logId,
            'company_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Holding tree (subsidiaries)
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_subsidiaries_holding_id(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        // subsidiary points at dup as its holding parent
        $subsidiary = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'holding_id' => $dup->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('crm_companies', [
            'id' => $subsidiary->id,
            'holding_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Acquisition channel history (polymorphic)
    // -----------------------------------------------------------------------

    public function test_merge_re_parents_acquisition_channel_history(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $histId = DB::table('acquisition_channel_history')->insertGetId([
            'entity_type' => 'company',
            'entity_id' => $dup->id,
            'old_channel_id' => null,
            'new_channel_id' => null,
            'changed_by' => $this->admin->id,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
        $this->assertDatabaseHas('acquisition_channel_history', [
            'id' => $histId,
            'entity_type' => 'company',
            'entity_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Atomicity
    // -----------------------------------------------------------------------

    public function test_merge_is_atomic_master_survives_dup_is_soft_deleted(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        // Master is alive, dup is soft-deleted.
        $this->assertDatabaseHas('crm_companies', ['id' => $master->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('crm_companies', ['id' => $dup->id]);
    }

    // -----------------------------------------------------------------------
    // Multi-dup: multiple duplicates merged in one call
    // -----------------------------------------------------------------------

    public function test_merge_multiple_duplicates_re_parents_all_deals(): void
    {
        $master = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup1 = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $dup2 = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $deal1 = Deal::factory()->create(['company_id' => $dup1->id, 'owner_user_id' => $this->admin->id]);
        $deal2 = Deal::factory()->create(['company_id' => $dup2->id, 'owner_user_id' => $this->admin->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup1->id, $dup2->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup1->id]);
        $this->assertSoftDeleted('crm_companies', ['id' => $dup2->id]);
        $this->assertDatabaseHas('deals', ['id' => $deal1->id, 'company_id' => $master->id]);
        $this->assertDatabaseHas('deals', ['id' => $deal2->id, 'company_id' => $master->id]);
    }
}
