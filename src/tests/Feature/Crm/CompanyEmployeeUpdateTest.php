<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\EmploymentStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CompanyEmployeeUpdateTest — verifies PATCH /companies/{company}/employees/{contact}.
 *
 * Covers:
 *  - 200 with updated link returned when employment_status changes
 *  - 422 when employment_status is missing or invalid
 *  - 404 when the link does not exist
 *  - 403 when the user cannot manage employees on the company
 *  - is_primary flip clears old primary link on the contact
 *  - position field update
 */
class CompanyEmployeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers ----

    private function actingAsManager(?int $ownedCompanyOwnerId = null): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function makeLink(Company $company, Contact $contact, array $attrs = []): ContactCompanyLink
    {
        return ContactCompanyLink::create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'employment_status' => EmploymentStatus::Works->value,
            'is_primary' => false,
        ], $attrs));
    }

    // ---- success cases ----

    public function test_update_changes_employment_status_to_left(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact, ['employment_status' => EmploymentStatus::Works->value]);

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'left',
        ])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'left')
            ->assertJsonPath('data.contact_id', $contact->id)
            ->assertJsonPath('data.company_id', $company->id);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'employment_status' => 'left',
        ]);
    }

    public function test_update_changes_employment_status_to_works(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact, ['employment_status' => EmploymentStatus::Left->value]);

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'works',
        ])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'works');
    }

    public function test_update_can_also_change_position(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact);

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'works',
            'position' => 'Senior Engineer',
        ])
            ->assertOk()
            ->assertJsonPath('data.position', 'Senior Engineer');

        $this->assertDatabaseHas('crm_contact_company_links', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'position' => 'Senior Engineer',
        ]);
    }

    public function test_update_is_primary_flag_clears_old_primary_for_contact(): void
    {
        $user = $this->actingAsManager();
        $companyA = Company::factory()->create(['owner_user_id' => $user->id]);
        $companyB = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();

        // companyA is the current primary
        $this->makeLink($companyA, $contact, ['is_primary' => true]);
        // companyB is not primary
        $this->makeLink($companyB, $contact, ['is_primary' => false]);

        // Update companyB link to is_primary = true
        $this->patchJson("/api/companies/{$companyB->id}/employees/{$contact->id}", [
            'employment_status' => 'works',
            'is_primary' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_primary', true);

        // companyA must no longer be primary
        $this->assertDatabaseHas('crm_contact_company_links', [
            'company_id' => $companyA->id,
            'contact_id' => $contact->id,
            'is_primary' => false,
        ]);

        // companyB is now primary
        $this->assertDatabaseHas('crm_contact_company_links', [
            'company_id' => $companyB->id,
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);
    }

    // ---- validation errors ----

    public function test_422_when_employment_status_missing(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact);

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_status']);
    }

    public function test_422_when_employment_status_is_invalid(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact);

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'fired',  // invalid value
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_status']);
    }

    // ---- not found ----

    public function test_404_when_link_does_not_exist(): void
    {
        $user = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $contact = Contact::factory()->create();
        // No link created

        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'left',
        ])
            ->assertNotFound();
    }

    // ---- authorization ----

    public function test_403_when_user_cannot_manage_employees(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($other, ['*']);

        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        $contact = Contact::factory()->create();
        $this->makeLink($company, $contact);

        // $other is neither owner nor responsible — policy returns false
        $this->patchJson("/api/companies/{$company->id}/employees/{$contact->id}", [
            'employment_status' => 'left',
        ])
            ->assertForbidden();
    }
}
