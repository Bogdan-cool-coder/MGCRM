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
 * Э2 finding #3 — LinkContactCompanyRequest hardening.
 *
 * The link endpoints take the OTHER side of the link from the body
 * (company_id on /contacts/{contact}/companies, contact_id on
 * /companies/{company}/employees). Two holes are closed:
 *  1. the FK id was read from an UNvalidated $request->input() — now it is a
 *     declared exists rule, so a bogus id is a clean 422 (not a downstream
 *     query error / silent no-op);
 *  2. only the PARENT entity was authorized — the linked entity's visibility was
 *     never checked, so a manager could link their own contact to a company
 *     outside their scope (or attach a foreign contact to their company),
 *     leaking that the record exists. Now the controller authorizes VIEW on the
 *     linked entity too → 403 when it is out of scope.
 *
 * A manager has Own visibility scope: they can see a company/contact only when
 * they own it. The factories default the owner columns to null, so a record
 * owned by ANOTHER user is invisible to the acting manager — the fixture for the
 * 403 cases.
 */
class LinkContactCompanyAuthzTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsManager(): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // ---- /contacts/{contact}/companies (company_id in body) --------------

    public function test_linking_a_visible_company_to_own_contact_succeeds(): void
    {
        $manager = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $manager->id]);
        $company = Company::factory()->create(['owner_user_id' => $manager->id]);

        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $company->id,
        ])->assertCreated();

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_linking_an_invisible_company_is_forbidden(): void
    {
        $manager = $this->actingAsManager();
        $other = User::factory()->create(['role' => Role::Manager]);

        $contact = Contact::factory()->create(['owner_id' => $manager->id]);
        // Owned by someone else → outside the acting manager's Own scope.
        $company = Company::factory()->create(['owner_user_id' => $other->id]);

        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $company->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_linking_a_nonexistent_company_id_is_422(): void
    {
        $manager = $this->actingAsManager();
        $contact = Contact::factory()->create(['owner_id' => $manager->id]);

        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    // ---- /companies/{company}/employees (contact_id in body) -------------

    public function test_linking_a_visible_contact_to_own_company_succeeds(): void
    {
        $manager = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $manager->id]);
        $contact = Contact::factory()->create(['owner_id' => $manager->id]);

        $this->postJson("/api/companies/{$company->id}/employees", [
            'contact_id' => $contact->id,
            'employment_status' => 'works',
        ])->assertCreated();

        $this->assertDatabaseHas('crm_contact_company_links', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_linking_an_invisible_contact_is_forbidden(): void
    {
        $manager = $this->actingAsManager();
        $other = User::factory()->create(['role' => Role::Manager]);

        $company = Company::factory()->create(['owner_user_id' => $manager->id]);
        // Owned by someone else → outside the acting manager's Own scope.
        $contact = Contact::factory()->create(['owner_id' => $other->id]);

        $this->postJson("/api/companies/{$company->id}/employees", [
            'contact_id' => $contact->id,
            'employment_status' => 'works',
        ])->assertForbidden();

        $this->assertDatabaseMissing('crm_contact_company_links', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_linking_a_nonexistent_contact_id_is_422(): void
    {
        $manager = $this->actingAsManager();
        $company = Company::factory()->create(['owner_user_id' => $manager->id]);

        $this->postJson("/api/companies/{$company->id}/employees", [
            'contact_id' => 999999,
            'employment_status' => 'works',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_id');
    }
}
