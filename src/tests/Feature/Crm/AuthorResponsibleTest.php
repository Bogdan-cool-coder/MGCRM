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
 * Wave 5: Author (created_by) vs Responsible (owner) separation tests.
 *
 * Verifies:
 * 1. created_by_id set on create = creator (Contact + Company)
 * 2. owner_id / owner_user_id defaults to creator on create (Contact + Company)
 * 3. created_by_id is immutable (update never changes it)
 * 4. bulk assign_owner changes owner_id, NOT created_by_id (Contact)
 * 5. bulk assign_responsible changes responsible_user_id, NOT created_by_id (Company)
 * 6. API resource exposes `author` (creator) and `owner`/`responsible_user` separately
 */
class AuthorResponsibleTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================
    // CONTACT
    // ============================================================

    public function test_contact_create_sets_created_by_id_to_creator(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', ['full_name' => 'Author Test'])->assertCreated();

        $this->assertDatabaseHas('crm_contacts', [
            'full_name' => 'Author Test',
            'created_by_id' => $user->id,
        ]);
    }

    public function test_contact_create_defaults_owner_to_creator(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', ['full_name' => 'Owner Default'])->assertCreated();

        $this->assertDatabaseHas('crm_contacts', [
            'full_name' => 'Owner Default',
            'owner_id' => $user->id,
            'created_by_id' => $user->id,
        ]);
    }

    public function test_contact_create_allows_explicit_owner_different_from_creator(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        $assignedOwner = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($creator, ['*']);

        $this->postJson('/api/contacts', [
            'full_name' => 'Explicit Owner',
            'owner_id' => $assignedOwner->id,
        ])->assertCreated();

        $this->assertDatabaseHas('crm_contacts', [
            'full_name' => 'Explicit Owner',
            'owner_id' => $assignedOwner->id,
            'created_by_id' => $creator->id,   // author = creator, not assignedOwner
        ]);
    }

    public function test_contact_update_does_not_change_created_by_id(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create([
            'owner_id' => $creator->id,
            'created_by_id' => $creator->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        // Attempt to slip created_by_id into an update payload
        $this->patchJson("/api/contacts/{$contact->id}", [
            'full_name' => 'Updated Name',
            'created_by_id' => $other->id,  // must be silently ignored
        ])->assertOk();

        $this->assertDatabaseHas('crm_contacts', [
            'id' => $contact->id,
            'full_name' => 'Updated Name',
            'created_by_id' => $creator->id,  // unchanged
        ]);
    }

    public function test_contact_bulk_assign_owner_changes_owner_not_created_by(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create([
            'owner_id' => $director->id,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$contact->id],
            'operation' => 'assign_owner',
            'owner_id' => $newOwner->id,
        ])->assertOk();

        $contact->refresh();
        $this->assertSame($newOwner->id, $contact->owner_id);
        $this->assertSame($director->id, $contact->created_by_id);  // unchanged
    }

    public function test_contact_resource_exposes_author_and_owner_separately(): void
    {
        $creator = User::factory()->create(['role' => Role::Director, 'full_name' => 'Author Person']);
        $owner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Owner Person']);
        $contact = Contact::factory()->create([
            'created_by_id' => $creator->id,
            'owner_id' => $owner->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        $response = $this->getJson("/api/contacts/{$contact->id}")->assertOk();

        // `author` = creator
        $response->assertJsonPath('data.author.id', $creator->id);
        $response->assertJsonPath('data.author.full_name', 'Author Person');

        // `owner` = responsible
        $response->assertJsonPath('data.owner.id', $owner->id);
        $response->assertJsonPath('data.owner.full_name', 'Owner Person');

        // Scalar IDs also present
        $response->assertJsonPath('data.created_by_id', $creator->id);
        $response->assertJsonPath('data.owner_id', $owner->id);
    }

    public function test_contact_list_includes_author_in_each_item(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        Contact::factory()->create([
            'created_by_id' => $creator->id,
            'owner_id' => $creator->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        $response = $this->getJson('/api/contacts')->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('author', $item);
        $this->assertNotNull($item['author']);
        $this->assertSame($creator->id, $item['author']['id']);
    }

    // ============================================================
    // COMPANY
    // ============================================================

    public function test_company_create_sets_created_by_id_to_creator(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/companies', ['name' => 'Author Company'])->assertCreated();

        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Author Company',
            'created_by_id' => $user->id,
        ]);
    }

    public function test_company_create_defaults_owner_user_to_creator(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/companies', ['name' => 'Owner Default Co'])->assertCreated();

        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Owner Default Co',
            'owner_user_id' => $user->id,
            'created_by_id' => $user->id,
        ]);
    }

    public function test_company_create_allows_explicit_responsible_different_from_creator(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        $responsible = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($creator, ['*']);

        $this->postJson('/api/companies', [
            'name' => 'Explicit Responsible Co',
            'responsible_user_id' => $responsible->id,
        ])->assertCreated();

        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Explicit Responsible Co',
            'responsible_user_id' => $responsible->id,
            'created_by_id' => $creator->id,  // author = creator
        ]);
    }

    public function test_company_update_does_not_change_created_by_id(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $creator->id,
            'created_by_id' => $creator->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        $this->patchJson("/api/companies/{$company->id}", [
            'name' => 'Updated Name',
            'created_by_id' => $other->id,  // must be silently ignored
        ])->assertOk();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $company->id,
            'name' => 'Updated Name',
            'created_by_id' => $creator->id,  // unchanged
        ]);
    }

    public function test_company_bulk_assign_responsible_changes_responsible_not_created_by(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $responsible = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $director->id,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/companies/bulk', [
            'company_ids' => [$company->id],
            'operation' => 'assign_responsible',
            'responsible_user_id' => $responsible->id,
        ])->assertOk();

        $company->refresh();
        $this->assertSame($responsible->id, $company->responsible_user_id);
        $this->assertSame($director->id, $company->created_by_id);  // unchanged
    }

    public function test_company_resource_exposes_author_and_responsible_separately(): void
    {
        $creator = User::factory()->create(['role' => Role::Director, 'full_name' => 'Company Author']);
        $responsible = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Company Responsible']);
        $company = Company::factory()->create([
            'created_by_id' => $creator->id,
            'owner_user_id' => $creator->id,
            'responsible_user_id' => $responsible->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}")->assertOk();

        // `author` = creator
        $response->assertJsonPath('data.author.id', $creator->id);
        $response->assertJsonPath('data.author.full_name', 'Company Author');

        // `responsible_user` = ответственный
        $response->assertJsonPath('data.responsible_user.id', $responsible->id);
        $response->assertJsonPath('data.responsible_user.full_name', 'Company Responsible');

        // Scalar IDs
        $response->assertJsonPath('data.created_by_id', $creator->id);
        $response->assertJsonPath('data.responsible_user_id', $responsible->id);
    }

    public function test_company_list_includes_author_in_each_item(): void
    {
        $creator = User::factory()->create(['role' => Role::Director]);
        Company::factory()->create([
            'created_by_id' => $creator->id,
            'owner_user_id' => $creator->id,
        ]);

        Sanctum::actingAs($creator, ['*']);

        $response = $this->getJson('/api/companies')->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('author', $item);
        $this->assertNotNull($item['author']);
        $this->assertSame($creator->id, $item['author']['id']);
    }
}
