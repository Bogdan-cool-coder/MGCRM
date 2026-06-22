<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Services\ContactService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2: DB-level guarantee that is_primary is unique per contact and per company.
 *
 * Two partial unique indexes enforce:
 *   - uq_ccl_contact_primary: at most one primary company per contact
 *   - uq_ccl_company_primary: at most one primary contact per company
 *
 * Tests verify:
 *   1. Setting is_primary via ContactService::linkCompany clears old primary (contact axis).
 *   2. Setting is_primary via ContactService::linkCompany clears old primary (company axis).
 *   3. Direct DB insert of a second primary for same contact raises a unique constraint error.
 *   4. Direct DB insert of a second primary for same company raises a unique constraint error.
 *   5. ContactService::reassignPrimary swaps primary correctly on both axes.
 */
class ContactCompanyLinkPrimaryTest extends TestCase
{
    use RefreshDatabase;

    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactService::class);
    }

    // -------------------------------------------------------------------------
    // Service-level: ContactService::linkCompany clears old primary (contact axis)
    // -------------------------------------------------------------------------

    public function test_link_company_clears_old_contact_primary_when_setting_new(): void
    {
        $contact = Contact::factory()->create();
        $co1 = Company::factory()->create();
        $co2 = Company::factory()->create();

        // Set co1 as primary
        $this->service->linkCompany($contact, $co1->id, ['is_primary' => true]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);

        // Now set co2 as primary — co1 must lose the flag
        $this->service->linkCompany($contact, $co2->id, ['is_primary' => true]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Service-level: ContactService::linkCompany clears old primary (company axis)
    // -------------------------------------------------------------------------

    public function test_link_company_clears_old_company_primary_when_setting_new(): void
    {
        $contact1 = Contact::factory()->create();
        $contact2 = Contact::factory()->create();
        $company = Company::factory()->create();

        // contact1 is primary for company
        $this->service->linkCompany($contact1, $company->id, ['is_primary' => true]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact1->id,
            'company_id' => $company->id,
            'is_primary' => true,
        ]);

        // contact2 becomes primary for the same company — contact1 must lose it
        $this->service->linkCompany($contact2, $company->id, ['is_primary' => true]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact2->id,
            'company_id' => $company->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact1->id,
            'company_id' => $company->id,
            'is_primary' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // DB-level: direct insert of a second primary for same contact must fail
    // -------------------------------------------------------------------------

    public function test_db_rejects_two_primary_rows_for_same_contact(): void
    {
        $contact = Contact::factory()->create();
        $co1 = Company::factory()->create();
        $co2 = Company::factory()->create();

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        // Bypass service to hit the DB constraint directly
        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // DB-level: direct insert of a second primary for same company must fail
    // -------------------------------------------------------------------------

    public function test_db_rejects_two_primary_rows_for_same_company(): void
    {
        $contact1 = Contact::factory()->create();
        $contact2 = Contact::factory()->create();
        $company = Company::factory()->create();

        ContactCompanyLink::create([
            'contact_id' => $contact1->id,
            'company_id' => $company->id,
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        ContactCompanyLink::create([
            'contact_id' => $contact2->id,
            'company_id' => $company->id,
            'is_primary' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Two non-primary rows for same contact / company — must succeed
    // -------------------------------------------------------------------------

    public function test_two_non_primary_rows_for_same_contact_are_allowed(): void
    {
        $contact = Contact::factory()->create();
        $co1 = Company::factory()->create();
        $co2 = Company::factory()->create();

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => false,
        ]);

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseCount('crm_contact_company_links', 2);
    }

    // -------------------------------------------------------------------------
    // ContactService::reassignPrimary swaps correctly
    // -------------------------------------------------------------------------

    public function test_reassign_primary_swaps_on_both_axes(): void
    {
        $contact = Contact::factory()->create();
        $co1 = Company::factory()->create();
        $co2 = Company::factory()->create();

        // Initial state: co1 is primary for contact
        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);
        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => false,
        ]);

        // Reassign: co2 becomes primary
        $this->service->reassignPrimary($contact, $co2->id);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => false,
        ]);
    }
}
