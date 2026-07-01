<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DedupWave3bTest — backend blockers for the MergeDialog 2.0 (Wave 3B).
 *
 * Covers:
 *   B-2  field_overrides: per-field source selection in merge
 *   B-1  candidate aggregates: open_deals_count / company_links_count / activities_count
 *   3.4  requisite criterion: scanAllCompanies groups by current bank account
 *   P5   bulk-merge: merge endpoint accepts arbitrary master_id + duplicate_ids
 *   PG   scanAll with records that have NO child data (activities/deals/links/requisites)
 *        must return 200 without E_WARNING from COUNT(*) aliasing (PostgreSQL regression).
 */
class DedupWave3bTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // =========================================================================
    // B-2 — field_overrides
    // =========================================================================

    public function test_merge_contact_applies_field_overrides_from_duplicate(): void
    {
        $master = Contact::factory()->create([
            'full_name' => 'Иван Иванов',
            'phone' => '+70001111111',
            'email' => 'master@example.com',
            'notes' => null,
            'source' => 'own_contact',
            'owner_id' => $this->admin->id,
        ]);

        $dup = Contact::factory()->create([
            'full_name' => 'Иван Иванов (дубль)',
            'phone' => '+70002222222',
            'email' => 'dup@example.com',
            'notes' => 'Важная заметка',
            'source' => 'advertisement',
            'owner_id' => $this->admin->id,
        ]);

        // Override: take `notes` and `source` from the duplicate record
        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            'field_overrides' => [
                'notes' => $dup->id,
                'source' => $dup->id,
            ],
        ])->assertOk();

        $master->refresh();

        // Overridden fields come from dup
        $this->assertSame('Важная заметка', $master->notes);
        $this->assertSame('advertisement', $master->source);

        // Non-overridden fields stay from master
        $this->assertSame('Иван Иванов', $master->full_name);
        $this->assertSame('master@example.com', $master->email);

        // Duplicate is soft-deleted
        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
    }

    public function test_merge_contact_with_master_as_field_override_source(): void
    {
        $master = Contact::factory()->create([
            'full_name' => 'Правильное Имя',
            'notes' => 'Заметки мастера',
            'owner_id' => $this->admin->id,
        ]);

        $dup = Contact::factory()->create([
            'full_name' => 'Неправильное Имя',
            'notes' => 'Заметки дубля',
            'owner_id' => $this->admin->id,
        ]);

        // Explicitly override notes from master (no-op effectively — keeps master value)
        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            'field_overrides' => [
                'notes' => $master->id,
            ],
        ])->assertOk();

        $master->refresh();
        $this->assertSame('Правильное Имя', $master->full_name);
        $this->assertSame('Заметки мастера', $master->notes);
    }

    public function test_merge_contact_without_field_overrides_behaves_as_before(): void
    {
        $master = Contact::factory()->create([
            'full_name' => 'Исходное Имя',
            'notes' => 'Исходная заметка',
            'owner_id' => $this->admin->id,
        ]);

        $dup = Contact::factory()->create([
            'full_name' => 'Другое Имя',
            'notes' => 'Другая заметка',
            'owner_id' => $this->admin->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            // No field_overrides — backward-compatible
        ])->assertOk();

        $master->refresh();
        // Master's own values are preserved
        $this->assertSame('Исходное Имя', $master->full_name);
        $this->assertSame('Исходная заметка', $master->notes);
    }

    public function test_merge_contact_rejects_field_override_with_non_whitelisted_key(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            'field_overrides' => [
                'owner_id' => $dup->id,  // NOT in whitelist
            ],
        ])->assertStatus(422);
    }

    public function test_merge_contact_rejects_field_override_with_unknown_source_id(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $outsider = Contact::factory()->create(['owner_id' => $this->admin->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            'field_overrides' => [
                'notes' => $outsider->id,  // Not master and not in duplicate_ids
            ],
        ])->assertStatus(422);
    }

    public function test_merge_company_applies_field_overrides(): void
    {
        $master = Company::factory()->create([
            'name' => 'ТОО Мастер',
            'city' => 'Алматы',
            'notes' => null,
            'owner_user_id' => $this->admin->id,
        ]);

        $dup = Company::factory()->create([
            'name' => 'ТОО Дубль',
            'city' => 'Нур-Султан',
            'notes' => 'Контрагент с 2020',
            'owner_user_id' => $this->admin->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
            'field_overrides' => [
                'city' => $dup->id,
                'notes' => $dup->id,
            ],
        ])->assertOk();

        $master->refresh();
        $this->assertSame('Нур-Султан', $master->city);
        $this->assertSame('Контрагент с 2020', $master->notes);
        $this->assertSame('ТОО Мастер', $master->name);  // Not overridden
    }

    // =========================================================================
    // B-1 — candidate aggregates in global scan
    // =========================================================================

    public function test_global_scan_contact_candidates_include_aggregates(): void
    {
        $c1 = Contact::factory()->create([
            'email' => 'dup-agg@example.com',
            'owner_id' => $this->admin->id,
        ]);
        $c2 = Contact::factory()->create([
            'email' => 'dup-agg@example.com',
            'owner_id' => $this->admin->id,
        ]);

        // Create 2 activities for c1
        DB::table('activities')->insert([
            ['kind' => 'note', 'title' => 'Note 1', 'target_type' => 'contact', 'target_id' => $c1->id, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'note', 'title' => 'Note 2', 'target_type' => 'contact', 'target_id' => $c1->id, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Link c1 to a company
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        ContactCompanyLink::create([
            'contact_id' => $c1->id,
            'company_id' => $company->id,
            'is_primary' => true,
        ]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')
            ->assertOk();

        $groups = $response->json('data');
        $this->assertNotEmpty($groups);

        // Find the group containing c1
        $group = collect($groups)->first(fn ($g) => collect($g['entities'])->contains('id', $c1->id));
        $this->assertNotNull($group, 'Group containing c1 not found in scan response');

        $c1Data = collect($group['entities'])->firstWhere('id', $c1->id);
        $this->assertNotNull($c1Data);

        // Aggregates must be present
        $this->assertArrayHasKey('activities_count', $c1Data);
        $this->assertArrayHasKey('company_links_count', $c1Data);
        $this->assertArrayHasKey('open_deals_count', $c1Data);

        $this->assertSame(2, $c1Data['activities_count']);
        $this->assertSame(1, $c1Data['company_links_count']);
        $this->assertSame(0, $c1Data['open_deals_count']);
    }

    public function test_global_scan_company_candidates_include_aggregates(): void
    {
        $co1 = Company::factory()->create([
            'tax_id' => 'AGG-TAX-001',
            'owner_user_id' => $this->admin->id,
        ]);
        $co2 = Company::factory()->create([
            'tax_id' => 'AGG-TAX-001',
            'owner_user_id' => $this->admin->id,
        ]);

        // Create 3 activities for co1
        DB::table('activities')->insert([
            ['kind' => 'note', 'title' => 'Act 1', 'target_type' => 'company', 'target_id' => $co1->id, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'note', 'title' => 'Act 2', 'target_type' => 'company', 'target_id' => $co1->id, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'note', 'title' => 'Act 3', 'target_type' => 'company', 'target_id' => $co1->id, 'created_by_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Link a contact to co1
        $contact = Contact::factory()->create(['owner_id' => $this->admin->id]);
        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=company')
            ->assertOk();

        $groups = $response->json('data');
        $this->assertNotEmpty($groups);

        $group = collect($groups)->first(fn ($g) => collect($g['entities'])->contains('id', $co1->id));
        $this->assertNotNull($group);

        $co1Data = collect($group['entities'])->firstWhere('id', $co1->id);
        $this->assertNotNull($co1Data);

        $this->assertArrayHasKey('activities_count', $co1Data);
        $this->assertArrayHasKey('company_links_count', $co1Data);
        $this->assertArrayHasKey('open_deals_count', $co1Data);

        $this->assertSame(3, $co1Data['activities_count']);
        $this->assertSame(1, $co1Data['company_links_count']);
    }

    // =========================================================================
    // 3.4 — requisite grouping criterion
    // =========================================================================

    public function test_global_scan_companies_groups_by_current_requisite_account(): void
    {
        $sharedAccount = 'KZ123456789012345678';
        $bankDetails = ['account' => $sharedAccount, 'bank_name' => 'Халык Банк', 'bank_bic' => 'HSBKKZKX'];

        $co1 = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $co2 = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        // co3 has a different account — should NOT be grouped
        $co3 = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        // Attach current requisites
        CompanyRequisite::create([
            'company_id' => $co1->id,
            'legal_name' => $co1->legal_name,
            'bank_details' => $bankDetails,
            'is_current' => true,
        ]);

        CompanyRequisite::create([
            'company_id' => $co2->id,
            'legal_name' => $co2->legal_name,
            'bank_details' => $bankDetails,
            'is_current' => true,
        ]);

        CompanyRequisite::create([
            'company_id' => $co3->id,
            'legal_name' => $co3->legal_name,
            'bank_details' => ['account' => 'OTHER-ACCOUNT-9999'],
            'is_current' => true,
        ]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=company')
            ->assertOk();

        $groups = $response->json('data');

        // Find the group that contains both co1 and co2
        $group = collect($groups)->first(function ($g) use ($co1, $co2) {
            $ids = collect($g['entities'])->pluck('id');
            return $ids->contains($co1->id) && $ids->contains($co2->id);
        });

        $this->assertNotNull($group, 'Expected a duplicate group for companies sharing a requisite account');

        // co3 should NOT be in this group
        $groupIds = collect($group['entities'])->pluck('id');
        $this->assertFalse($groupIds->contains($co3->id));
    }

    public function test_candidate_resource_includes_requisite_fields_for_company(): void
    {
        $bankDetails = [
            'account' => 'KZ9001234567890',
            'bank_name' => 'Kaspi Bank',
            'bank_bic' => 'CASPKZKA',
        ];

        $co1 = Company::factory()->create([
            'tax_id' => 'REQ-TAX-7777',
            'owner_user_id' => $this->admin->id,
        ]);
        $co2 = Company::factory()->create([
            'tax_id' => 'REQ-TAX-7777',
            'owner_user_id' => $this->admin->id,
        ]);

        CompanyRequisite::create([
            'company_id' => $co1->id,
            'legal_name' => $co1->legal_name,
            'bank_details' => $bankDetails,
            'is_current' => true,
        ]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=company')
            ->assertOk();

        $groups = $response->json('data');
        $group = collect($groups)->first(fn ($g) => collect($g['entities'])->contains('id', $co1->id));
        $this->assertNotNull($group);

        $co1Data = collect($group['entities'])->firstWhere('id', $co1->id);
        $this->assertNotNull($co1Data);

        // Requisite fields must be present
        $this->assertArrayHasKey('requisite_account', $co1Data);
        $this->assertArrayHasKey('requisite_bank_code', $co1Data);
        $this->assertArrayHasKey('requisite_bank_name', $co1Data);
        $this->assertSame('KZ9001234567890', $co1Data['requisite_account']);
        $this->assertSame('CASPKZKA', $co1Data['requisite_bank_code']);
        $this->assertSame('Kaspi Bank', $co1Data['requisite_bank_name']);
    }

    // =========================================================================
    // P5 — bulk merge: arbitrary master + duplicate_ids (no scan required)
    // =========================================================================

    public function test_bulk_merge_arbitrary_contacts_no_scan_required(): void
    {
        // Create 3 contacts that do NOT share any dedup criterion —
        // they would never appear in a scan group. The endpoint must still merge them.
        $master = Contact::factory()->create([
            'full_name' => 'Главный Контакт',
            'email' => 'master-bulk@example.com',
            'owner_id' => $this->admin->id,
        ]);
        $dup1 = Contact::factory()->create([
            'full_name' => 'Дубль Один',
            'email' => 'dup1-bulk@example.com',
            'owner_id' => $this->admin->id,
        ]);
        $dup2 = Contact::factory()->create([
            'full_name' => 'Дубль Два',
            'email' => 'dup2-bulk@example.com',
            'owner_id' => $this->admin->id,
        ]);

        // Attach a company to dup1 — it must be transferred to master after merge
        $co = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        ContactCompanyLink::create([
            'contact_id' => $dup1->id,
            'company_id' => $co->id,
            'is_primary' => false,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup1->id, $dup2->id],
        ])->assertOk();

        // Both dups soft-deleted
        $this->assertSoftDeleted('crm_contacts', ['id' => $dup1->id]);
        $this->assertSoftDeleted('crm_contacts', ['id' => $dup2->id]);

        // Company link transferred to master
        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $master->id,
            'company_id' => $co->id,
        ]);
    }

    public function test_bulk_merge_arbitrary_companies_no_scan_required(): void
    {
        $master = Company::factory()->create([
            'name' => 'Главная Компания',
            'owner_user_id' => $this->admin->id,
        ]);
        $dup1 = Company::factory()->create([
            'name' => 'Компания Один',
            'owner_user_id' => $this->admin->id,
        ]);
        $dup2 = Company::factory()->create([
            'name' => 'Компания Два',
            'owner_user_id' => $this->admin->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup1->id, $dup2->id],
        ])->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $dup1->id]);
        $this->assertSoftDeleted('crm_companies', ['id' => $dup2->id]);
    }

    public function test_bulk_merge_four_contacts_all_transferred(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $dups = Contact::factory()->count(3)->create(['owner_id' => $this->admin->id]);

        $dupIds = $dups->pluck('id')->all();

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => $dupIds,
        ])->assertOk();

        foreach ($dupIds as $dupId) {
            $this->assertSoftDeleted('crm_contacts', ['id' => $dupId]);
        }
    }

    // =========================================================================
    // PG regression: global scan with records that have NO child data
    // (empty activities / deals / company_links / requisites).
    // DedupService was using pluck(DB::raw('COUNT(*)'), ...) which works in
    // SQLite but throws E_WARNING → ErrorException (500) in PostgreSQL because
    // PG names the unaliased aggregate column "count", not "COUNT(*)".
    // Fixed by switching to selectRaw('..., COUNT(*) as cnt')->pluck('cnt', ...).
    // =========================================================================

    public function test_global_scan_contacts_200_when_candidates_have_no_activities_or_deals(): void
    {
        // Two contacts sharing an email — they will form a duplicate group.
        // Neither has activities, company links, or open deals.
        Contact::factory()->create([
            'email' => 'empty-data@example.com',
            'owner_id' => $this->admin->id,
        ]);
        Contact::factory()->create([
            'email' => 'empty-data@example.com',
            'owner_id' => $this->admin->id,
        ]);

        // Must return 200 and not throw (regression guard for PG COUNT(*) aliasing).
        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')
            ->assertOk();

        $groups = $response->json('data');
        $this->assertNotEmpty($groups);

        // All aggregate fields must be integers (0), never null.
        foreach ($groups as $group) {
            foreach ($group['entities'] as $entity) {
                $this->assertIsInt($entity['activities_count'], 'activities_count must be int');
                $this->assertIsInt($entity['open_deals_count'], 'open_deals_count must be int');
                $this->assertIsInt($entity['company_links_count'], 'company_links_count must be int');
                $this->assertSame(0, $entity['activities_count']);
                $this->assertSame(0, $entity['open_deals_count']);
            }
        }
    }

    public function test_global_scan_companies_200_when_candidates_have_no_activities_deals_or_requisites(): void
    {
        // Two companies sharing a tax_id — they will form a duplicate group.
        // Neither has activities, deal links, contact links, or current requisites.
        Company::factory()->create([
            'tax_id' => 'EMPTY-DATA-TAXID',
            'owner_user_id' => $this->admin->id,
        ]);
        Company::factory()->create([
            'tax_id' => 'EMPTY-DATA-TAXID',
            'owner_user_id' => $this->admin->id,
        ]);

        // Must return 200 and not throw (regression guard for PG COUNT(*) aliasing).
        $response = $this->getJson('/api/crm/dedup/scan?scope=company')
            ->assertOk();

        $groups = $response->json('data');
        $this->assertNotEmpty($groups);

        // All aggregate fields must be integers (0), never null; requisite fields null-safe.
        foreach ($groups as $group) {
            foreach ($group['entities'] as $entity) {
                $this->assertIsInt($entity['activities_count'], 'activities_count must be int');
                $this->assertIsInt($entity['open_deals_count'], 'open_deals_count must be int');
                $this->assertIsInt($entity['company_links_count'], 'company_links_count must be int');
                $this->assertSame(0, $entity['activities_count']);
                $this->assertSame(0, $entity['open_deals_count']);
                $this->assertSame(0, $entity['company_links_count']);
                // Requisite fields must exist in response (null when no requisite).
                $this->assertArrayHasKey('requisite_account', $entity);
                $this->assertArrayHasKey('requisite_bank_code', $entity);
                $this->assertNull($entity['requisite_account']);
            }
        }
    }

    // =========================================================================
    // B-2 + P5 combined: field_overrides in bulk merge
    // =========================================================================

    public function test_bulk_merge_with_field_overrides_from_second_dup(): void
    {
        $master = Contact::factory()->create([
            'full_name' => 'Мастер',
            'notes' => null,
            'source' => 'own_contact',
            'owner_id' => $this->admin->id,
        ]);
        $dup1 = Contact::factory()->create([
            'full_name' => 'Дубль 1',
            'notes' => 'Из первого',
            'source' => 'referral',
            'owner_id' => $this->admin->id,
        ]);
        $dup2 = Contact::factory()->create([
            'full_name' => 'Дубль 2',
            'notes' => 'Из второго',
            'source' => 'advertisement',
            'owner_id' => $this->admin->id,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup1->id, $dup2->id],
            'field_overrides' => [
                'notes' => $dup2->id,
                'source' => $dup1->id,
            ],
        ])->assertOk();

        $master->refresh();
        $this->assertSame('Из второго', $master->notes);
        $this->assertSame('referral', $master->source);
        $this->assertSame('Мастер', $master->full_name);
    }
}
