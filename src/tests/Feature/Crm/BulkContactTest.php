<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for ContactBulkController (B7).
 * Tests: assign_owner, set_tags, all-or-nothing 403 for foreign contact.
 */
class BulkContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_assign_owner(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);

        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$a->id, $b->id],
            'operation' => 'assign_owner',
            'owner_id' => $newOwner->id,
        ])->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->assertDatabaseHas('crm_contacts', ['id' => $a->id, 'owner_id' => $newOwner->id]);
        $this->assertDatabaseHas('crm_contacts', ['id' => $b->id, 'owner_id' => $newOwner->id]);
    }

    public function test_bulk_set_tags(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$contact->id],
            'operation' => 'set_tags',
            'tags' => ['vip', 'partner'],
        ])->assertOk()
            ->assertJsonPath('data.processed', 1);

        $contact->refresh();
        $this->assertContains('vip', $contact->tags);
        $this->assertContains('partner', $contact->tags);
    }

    public function test_bulk_add_tag(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create(['owner_id' => $director->id, 'tags' => ['existing']]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$contact->id],
            'operation' => 'add_tag',
            'tag' => 'new_tag',
        ])->assertOk();

        $contact->refresh();
        $this->assertContains('existing', $contact->tags);
        $this->assertContains('new_tag', $contact->tags);
    }

    public function test_bulk_403_for_foreign_contact(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $foreignContact = Contact::factory()->create(['owner_id' => User::factory()->create()->id]);

        Sanctum::actingAs($manager, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$foreignContact->id],
            'operation' => 'assign_owner',
            'owner_id' => $manager->id,
        ])->assertStatus(403);
    }

    public function test_bulk_delete(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->deleteJson('/api/contacts/bulk', [
            'contact_ids' => [$a->id, $b->id],
        ])->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSoftDeleted('crm_contacts', ['id' => $a->id]);
        $this->assertSoftDeleted('crm_contacts', ['id' => $b->id]);
    }

    public function test_bulk_invalid_operation_returns_422(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/contacts/bulk', [
            'contact_ids' => [$contact->id],
            'operation' => 'not_a_valid_op',
        ])->assertStatus(422);
    }
}
