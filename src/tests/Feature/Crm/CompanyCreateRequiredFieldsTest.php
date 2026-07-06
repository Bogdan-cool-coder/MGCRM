<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal Create 2.0 (docs/specs/deal-create-2-contract.md §4): manual
 * `POST /api/companies` now requires the "Contact data" section
 * (phone/website/address) and the "Classification" section
 * (company_type_id/country_code/source). The "Responsible" block is removed —
 * responsible_user_id/owner_user_id are silently dropped, owner is always the
 * creating user.
 *
 * These rules are scoped to the manual-create FormRequest ONLY —
 * CompanyService::create() itself is untouched, so import/migration/merge/
 * express-create paths (which bypass StoreCompanyRequest) are unaffected
 * (CompanyMergeOrphansTest still green with untouched merge flow).
 */
class CompanyCreateRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCompanyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Co',
            'phone' => '+7 700 000 00 00',
            'website' => 'https://example.com',
            'address' => 'Test address 1',
            'company_type_id' => CompanyType::factory()->create()->id,
            'country_code' => 'kz',
            'source' => 'own_contact',
        ], $overrides);
    }

    private function actingManager(): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // ---- 422 when a required field is missing (one at a time) ----

    public function test_missing_phone_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['phone']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('phone');
    }

    public function test_missing_website_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['website']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('website');
    }

    public function test_missing_address_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['address']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('address');
    }

    public function test_missing_company_type_id_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['company_type_id']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('company_type_id');
    }

    public function test_missing_country_code_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['country_code']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('country_code');
    }

    public function test_missing_source_returns_422(): void
    {
        $this->actingManager();

        $payload = $this->validCompanyPayload();
        unset($payload['source']);

        $this->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('source');
    }

    public function test_bare_payload_fails_all_required_fields_at_once(): void
    {
        $this->actingManager();

        $response = $this->postJson('/api/companies', ['name' => 'Only Name'])
            ->assertStatus(422);

        foreach (['phone', 'website', 'address', 'company_type_id', 'country_code', 'source'] as $field) {
            $response->assertJsonValidationErrorFor($field);
        }
    }

    // ---- happy path: all required fields present → 201 ----

    public function test_full_payload_creates_company(): void
    {
        $this->actingManager();

        $response = $this->postJson('/api/companies', $this->validCompanyPayload(['name' => 'Full Payload Co']))
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Full Payload Co');
        $this->assertDatabaseHas('crm_companies', ['name' => 'Full Payload Co']);
    }

    // ---- owner = creator; responsible_user_id / owner_user_id from the form are ignored ----

    public function test_owner_is_always_the_creator(): void
    {
        $user = $this->actingManager();

        $response = $this->postJson('/api/companies', $this->validCompanyPayload(['name' => 'Owner Test Co']))
            ->assertCreated();

        $this->assertSame($user->id, $response->json('data.owner_user_id'));
        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Owner Test Co',
            'owner_user_id' => $user->id,
            'created_by_id' => $user->id,
        ]);
    }

    public function test_owner_user_id_from_form_is_ignored(): void
    {
        $creator = $this->actingManager();
        $other = User::factory()->create(['role' => Role::Manager]);

        $response = $this->postJson('/api/companies', $this->validCompanyPayload([
            'name' => 'Ignore Owner Co',
            'owner_user_id' => $other->id,
        ]))->assertCreated();

        // The foreign owner_user_id in the payload never reaches the service —
        // owner is always the creator, never a client-supplied value.
        $this->assertSame($creator->id, $response->json('data.owner_user_id'));
        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Ignore Owner Co',
            'owner_user_id' => $creator->id,
        ]);
        $this->assertDatabaseMissing('crm_companies', [
            'name' => 'Ignore Owner Co',
            'owner_user_id' => $other->id,
        ]);
    }

    public function test_responsible_user_id_from_form_is_ignored(): void
    {
        $creator = $this->actingManager();
        $other = User::factory()->create(['role' => Role::Manager]);

        $response = $this->postJson('/api/companies', $this->validCompanyPayload([
            'name' => 'Ignore Responsible Co',
            'responsible_user_id' => $other->id,
        ]))->assertCreated();

        // responsible_user_id is not in StoreCompanyRequest's rule-set at all —
        // it never reaches $request->validated(), so it stays null (no
        // "Responsible" block on the manual create form, decision #5).
        $this->assertNull($response->json('data.responsible_user_id'));
        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Ignore Responsible Co',
            'responsible_user_id' => null,
        ]);
    }

    // ---- email stays nullable (not in the required list, decision #5) ----

    public function test_email_stays_nullable(): void
    {
        $this->actingManager();

        $this->postJson('/api/companies', $this->validCompanyPayload(['name' => 'No Email Co']))
            ->assertCreated()
            ->assertJsonPath('data.email', null);
    }

    // ---- CompanyResource exposes the new required-section fields (for the FE card) ----

    public function test_company_resource_exposes_contact_and_classification_fields(): void
    {
        $user = $this->actingManager();
        $type = CompanyType::factory()->create();

        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'phone' => '+7 700 111 22 33',
            'website' => 'https://acme.example',
            'address' => 'Some street 5',
            'company_type_id' => $type->id,
            'country_code' => 'ru',
            'source' => 'own_contact',
        ]);

        $response = $this->getJson("/api/companies/{$company->id}")->assertOk();

        $response->assertJsonPath('data.phone', '+7 700 111 22 33');
        $response->assertJsonPath('data.website', 'https://acme.example');
        $response->assertJsonPath('data.address', 'Some street 5');
        $response->assertJsonPath('data.company_type_id', $type->id);
        $response->assertJsonPath('data.country_code', 'ru');
        $response->assertJsonPath('data.source', 'own_contact');
    }

    // ---- import/merge/programmatic create paths are NOT affected (service untouched) ----

    public function test_company_service_create_still_works_without_required_fields(): void
    {
        // Simulates an import/ETL path that calls CompanyService::create() directly,
        // bypassing StoreCompanyRequest entirely — must NOT require the new fields.
        $user = $this->actingManager();
        $service = $this->app->make(CompanyService::class);

        $company = $service->create(['name' => 'Imported Co (no contact/classification)'], $user);

        $this->assertNotNull($company->id);
        $this->assertSame($user->id, $company->owner_user_id);
        $this->assertDatabaseHas('crm_companies', ['name' => 'Imported Co (no contact/classification)']);
    }
}
