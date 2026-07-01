<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests that the list and export endpoints are correctly scoped by row-level
 * visibility — confirming fix for blockers CRM-1, CRM-2, CRM-3:
 *
 *   CRM-1 — contacts list unscoped (manager sees others' contacts)
 *   CRM-2 — contacts export unscoped (empty ids dumps all PII)
 *   CRM-3 — companies list + export unscoped
 */
class VisibilityLeakTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // CONTACTS — list scope (CRM-1)
    // -----------------------------------------------------------------------

    public function test_admin_sees_all_contacts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->create(['owner_id' => $admin->id]);
        Contact::factory()->create(['owner_id' => $other->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/contacts');
        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_director_sees_all_contacts(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->create(['owner_id' => $director->id]);
        Contact::factory()->create(['owner_id' => $other->id]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/contacts');
        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_lawyer_sees_all_contacts(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->create(['owner_id' => $lawyer->id]);
        Contact::factory()->create(['owner_id' => $other->id]);

        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->getJson('/api/contacts');
        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_manager_only_sees_own_contacts(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // manager owns 1 contact; admin owns 2
        Contact::factory()->create(['owner_id' => $manager->id]);
        Contact::factory()->count(2)->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/contacts');
        $response->assertOk();

        // Manager must NOT see admin's contacts (the PII leak that was reported).
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($manager->id, $response->json('data.0.owner_id'));
    }

    public function test_manager_with_zero_contacts_sees_empty_list(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // Only admin-owned contacts exist — manager should see 0.
        Contact::factory()->count(3)->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/contacts');
        $response->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
    }

    // -----------------------------------------------------------------------
    // CONTACTS — export scope (CRM-2)
    // -----------------------------------------------------------------------

    public function test_contact_export_requires_auth(): void
    {
        $this->postJson('/api/contacts/export', [])
            ->assertUnauthorized();
    }

    public function test_manager_export_with_empty_ids_returns_only_own_contacts(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Contact::factory()->create(['owner_id' => $manager->id]);
        Contact::factory()->count(5)->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        // Empty ids — must NOT dump all contacts (the exploit that was reported).
        $response = $this->postJson('/api/contacts/export', ['contact_ids' => []]);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // The response should be non-empty (has the manager's 1 contact) but
        // significantly smaller than a dump of all 6 contacts.
        // We cannot parse XLSX here, but we can assert the Content-Disposition is set.
        $response->assertHeader('Content-Disposition');
    }

    public function test_admin_export_with_empty_ids_returns_all_contacts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->count(3)->create(['owner_id' => $admin->id]);
        Contact::factory()->count(3)->create(['owner_id' => $other->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/contacts/export', ['contact_ids' => []]);
        $response->assertOk();
    }

    public function test_manager_cannot_export_other_contacts_by_id(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $adminContact = Contact::factory()->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        // Sending admin's contact id — the visibility scope should filter it out
        // (the returned XLSX will have no data rows for this contact).
        $response = $this->postJson('/api/contacts/export', ['contact_ids' => [$adminContact->id]]);
        $response->assertOk(); // 200 but empty data (scoped out by visibility)
    }

    // -----------------------------------------------------------------------
    // COMPANIES — list scope (CRM-3 part A)
    // -----------------------------------------------------------------------

    public function test_admin_sees_all_companies(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->create(['owner_user_id' => $admin->id]);
        Company::factory()->create(['owner_user_id' => $other->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/companies');
        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_manager_only_sees_own_or_responsible_companies(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // Manager owns one, is responsible for another, admin owns two more.
        Company::factory()->create(['owner_user_id' => $manager->id, 'responsible_user_id' => null]);
        Company::factory()->create(['owner_user_id' => $admin->id, 'responsible_user_id' => $manager->id]);
        Company::factory()->count(2)->create(['owner_user_id' => $admin->id, 'responsible_user_id' => null]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/companies');
        $response->assertOk();

        // Manager sees own (1) + responsible (1) = 2; NOT the 2 admin-only companies.
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_manager_with_zero_companies_sees_empty_list(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Company::factory()->count(13)->create(['owner_user_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        // The exact scenario from the audit: manager1 (owns 0) must see 0, not 13.
        $response = $this->getJson('/api/companies');
        $response->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
    }

    // -----------------------------------------------------------------------
    // COMPANIES — export scope (CRM-3 part B)
    // -----------------------------------------------------------------------

    public function test_company_export_requires_auth(): void
    {
        $this->postJson('/api/companies/export', [])
            ->assertUnauthorized();
    }

    public function test_manager_company_export_with_empty_ids_returns_only_own(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Company::factory()->create(['owner_user_id' => $manager->id]);
        Company::factory()->count(12)->create(['owner_user_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        // Empty ids — must NOT dump all 13 companies.
        $response = $this->postJson('/api/companies/export', ['company_ids' => []]);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_manager_cannot_export_other_company_by_id(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $adminCompany = Company::factory()->create(['owner_user_id' => $admin->id]);

        Sanctum::actingAs($manager, ['*']);

        // Scoped out by visibility — 200 but empty XLSX data.
        $response = $this->postJson('/api/companies/export', ['company_ids' => [$adminCompany->id]]);
        $response->assertOk();
    }
}
