<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests that contact merge re-parents ALL child FK rows to the master contact
 * before soft-deleting the duplicate (Fix 4 — symmetric with mergeCompany).
 *
 * Tables covered: crm_contact_company_links, deal_contacts, contact_channels,
 * activities (polymorphic target), crm_contact_relations, crm_folders/crm_files.
 */
class ContactMergeOrphansTest extends TestCase
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

    public function test_merge_contact_re_parents_company_links(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        DB::table('crm_contact_company_links')->insert([
            'contact_id' => $dup->id,
            'company_id' => $company->id,
            'is_primary' => false,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $master->id,
            'company_id' => $company->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Deal-contact pivot
    // -----------------------------------------------------------------------

    public function test_merge_contact_re_parents_deal_contacts(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $this->admin->id,
        ]);

        $dcId = DB::table('deal_contacts')->insertGetId([
            'deal_id' => $deal->id,
            'contact_id' => $dup->id,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        $this->assertDatabaseHas('deal_contacts', [
            'id' => $dcId,
            'contact_id' => $master->id,
            'deal_id' => $deal->id,
        ]);
    }

    /**
     * If master is already on the deal, the dup's link must be removed
     * (not cause a unique violation).
     */
    public function test_merge_contact_deal_contacts_skips_existing_master_link(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $this->admin->id,
        ]);

        // Both master and dup are on the same deal
        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id,
            'contact_id' => $master->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id,
            'contact_id' => $dup->id,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        // Master's link must survive
        $this->assertDatabaseHas('deal_contacts', [
            'deal_id' => $deal->id,
            'contact_id' => $master->id,
        ]);
        // Dup's link must be gone (deleted as orphan)
        $this->assertDatabaseMissing('deal_contacts', [
            'deal_id' => $deal->id,
            'contact_id' => $dup->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Contact channels
    // -----------------------------------------------------------------------

    public function test_merge_contact_re_parents_contact_channels(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);

        $chanId = DB::table('contact_channels')->insertGetId([
            'contact_id' => $dup->id,
            'channel_type' => 'phone',
            'value' => '+70001234567',
            'is_primary_for_channel' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        $this->assertDatabaseHas('contact_channels', [
            'id' => $chanId,
            'contact_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Activities (polymorphic target)
    // -----------------------------------------------------------------------

    public function test_merge_contact_re_parents_activities(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);

        // Insert directly to avoid factory column-name issues.
        $activityId = DB::table('activities')->insertGetId([
            'kind' => 'task',
            'target_type' => 'contact',
            'target_id' => $dup->id,
            'title' => 'Follow up',
            'body' => null,
            'due_at' => null,
            'completed_at' => null,
            'completed_by_id' => null,
            'responsible_id' => $this->admin->id,
            'created_by_id' => $this->admin->id,
            'priority' => 'normal',
            'status' => 'new',
            'is_closed' => false,
            'progress_pct' => 0,
            'result_text' => null,
            'is_pinned' => false,
            'is_first_time_meeting' => false,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => false,
            'ftm_report_url' => null,
            'meeting_report_json' => null,
            'department_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        $this->assertDatabaseHas('activities', [
            'id' => $activityId,
            'target_type' => 'contact',
            'target_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Contact relations
    // -----------------------------------------------------------------------

    public function test_merge_contact_re_parents_contact_relations(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $third = Contact::factory()->create(['owner_id' => $this->admin->id]);

        // Relation: dup ↔ third (normalized: min_id → contact_id, max_id → related_contact_id)
        [$minId, $maxId] = $dup->id < $third->id
            ? [$dup->id, $third->id]
            : [$third->id, $dup->id];

        $relId = DB::table('crm_contact_relations')->insertGetId([
            'contact_id' => $minId,
            'related_contact_id' => $maxId,
            'relation_type' => 'colleague',
            'created_by_id' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);

        // The relation must now reference master instead of dup
        $rel = DB::table('crm_contact_relations')->where('id', $relId)->first();
        $this->assertNotNull($rel);
        $this->assertNotEquals($dup->id, $rel->contact_id);
        $this->assertNotEquals($dup->id, $rel->related_contact_id);
    }

    // -----------------------------------------------------------------------
    // CRM folders and files (polymorphic owner)
    // -----------------------------------------------------------------------

    public function test_merge_contact_re_parents_crm_folders_and_files(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);

        $folderId = DB::table('crm_folders')->insertGetId([
            'owner_entity_type' => 'contact',
            'owner_entity_id' => $dup->id,
            'name' => 'Documents',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fileId = DB::table('crm_files')->insertGetId([
            'folder_id' => $folderId,
            'owner_entity_type' => 'contact',
            'owner_entity_id' => $dup->id,
            'file_path' => 'contacts/dup/doc.pdf',
            'original_name' => 'doc.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by_user_id' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
        $this->assertDatabaseHas('crm_folders', [
            'id' => $folderId,
            'owner_entity_type' => 'contact',
            'owner_entity_id' => $master->id,
        ]);
        $this->assertDatabaseHas('crm_files', [
            'id' => $fileId,
            'owner_entity_type' => 'contact',
            'owner_entity_id' => $master->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // After merge: no rows reference the soft-deleted duplicate
    // -----------------------------------------------------------------------

    public function test_after_merge_no_rows_reference_deleted_dup(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $this->admin->id,
        ]);

        // Populate every linkable table for the dup
        DB::table('crm_contact_company_links')->insert([
            'contact_id' => $dup->id,
            'company_id' => $company->id,
            'is_primary' => false,
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id,
            'contact_id' => $dup->id,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contact_channels')->insert([
            'contact_id' => $dup->id,
            'channel_type' => 'email',
            'value' => 'dup@example.com',
            'is_primary_for_channel' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();

        $dupId = $dup->id;

        // None of the child tables must still point at the deleted dup
        $this->assertDatabaseMissing('crm_contact_company_links', ['contact_id' => $dupId]);
        $this->assertDatabaseMissing('deal_contacts', ['contact_id' => $dupId]);
        $this->assertDatabaseMissing('contact_channels', ['contact_id' => $dupId]);
        $this->assertDatabaseMissing('activities', ['target_type' => 'contact', 'target_id' => $dupId]);
    }
}
