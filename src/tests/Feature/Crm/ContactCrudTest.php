<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_list_contacts(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Contact::factory()->count(2)->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_manager_can_create_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', [
            'full_name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Иван Иванов');

        $this->assertDatabaseHas('crm_contacts', ['full_name' => 'Иван Иванов']);
    }

    public function test_create_contact_sets_created_by_id(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/contacts', [
            'full_name' => 'Author Test',
        ])->assertCreated();

        // created_by_id must equal the auth user
        $this->assertDatabaseHas('crm_contacts', [
            'full_name' => 'Author Test',
            'created_by_id' => $user->id,
        ]);

        // Resource should expose created_by_id
        $response->assertJsonPath('data.created_by_id', $user->id);
    }

    public function test_contact_full_name_is_required(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('full_name');
    }

    public function test_owner_can_view_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id);
    }

    public function test_foreign_manager_gets_403_on_contact(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_any_contact(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk();
    }

    public function test_owner_can_update_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", ['full_name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Updated Name');
    }

    public function test_owner_can_delete_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/contacts/{$contact->id}")
            ->assertOk();

        $this->assertSoftDeleted('crm_contacts', ['id' => $contact->id]);
    }

    // ---- M2M Links ----

    public function test_can_link_contact_to_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $company->id,
            'position' => 'Директор',
            'employment_status' => 'works',
            'is_primary' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'is_primary' => 1,
        ]);
    }

    public function test_primary_flag_reassigns_on_new_primary_link(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $company1 = Company::factory()->create(['owner_user_id' => $user->id]);
        $company2 = Company::factory()->create(['owner_user_id' => $user->id]);

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $company1->id,
            'employment_status' => 'works',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $company2->id,
            'is_primary' => true,
        ])
            ->assertCreated();

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company1->id,
            'is_primary' => 0,
        ]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company2->id,
            'is_primary' => 1,
        ]);
    }

    public function test_can_unlink_contact_from_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'employment_status' => 'works',
            'is_primary' => false,
        ]);

        $this->deleteJson("/api/contacts/{$contact->id}/companies/{$company->id}")
            ->assertOk();

        $this->assertDatabaseMissing('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    // ---- Custom Fields (extra_fields validation) ----

    public function test_extra_fields_stored_free_form_when_no_defs(): void
    {
        // No CustomFieldDef rows → free-form storage (backward compat).
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', [
            'full_name' => 'Extra Fields Test',
            'extra_fields' => ['my_key' => 'my_value'],
        ])->assertCreated();

        $this->assertDatabaseHas('crm_contacts', ['full_name' => 'Extra Fields Test']);
        $contact = Contact::where('full_name', 'Extra Fields Test')->firstOrFail();
        $this->assertSame('my_value', ($contact->extra_fields)['my_key']);
    }

    public function test_extra_fields_rejects_unknown_key_when_defs_exist(): void
    {
        // A def is defined for the contact scope → unknown keys must be rejected.
        CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'industry_note',
            'label' => 'Note',
            'field_type' => 'text',
        ]);

        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', [
            'full_name' => 'Bad Field Test',
            'extra_fields' => ['unknown_code' => 'value'],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('extra_fields');
    }

    public function test_update_contact_clears_extra_fields_on_null(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'extra_fields' => ['k' => 'v'],
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", [
            'extra_fields' => null,
        ])->assertOk();

        $contact->refresh();
        $this->assertSame([], $contact->extra_fields);
    }
}
