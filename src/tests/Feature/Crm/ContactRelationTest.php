<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\RelationType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for contact-to-contact relation CRUD (B1).
 * Tests: list (both sides visible), create, self-link prevention, delete.
 */
class ContactRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_relations_for_both_sides(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        // Create with normalized order (a < b)
        ContactRelation::create([
            'contact_id' => min($a->id, $b->id),
            'related_contact_id' => max($a->id, $b->id),
            'relation_type' => RelationType::Partner->value,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        // List from A's perspective
        $this->getJson("/api/contacts/{$a->id}/relations")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // List from B's perspective — same relation visible
        $this->getJson("/api/contacts/{$b->id}/relations")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_create_relation_normalises_min_max_ordering(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/contacts/{$a->id}/relations", [
            'related_contact_id' => $b->id,
            'relation_type' => RelationType::Colleague->value,
        ])->assertCreated()
            ->assertJsonPath('data.relation_type', RelationType::Colleague->value);

        // DB row should always have min → contact_id
        $minId = min($a->id, $b->id);
        $maxId = max($a->id, $b->id);
        $this->assertDatabaseHas('crm_contact_relations', [
            'contact_id' => $minId,
            'related_contact_id' => $maxId,
            'relation_type' => RelationType::Colleague->value,
        ]);
    }

    public function test_create_self_link_returns_422(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $contact = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/contacts/{$contact->id}/relations", [
            'related_contact_id' => $contact->id,
            'relation_type' => RelationType::Partner->value,
        ])->assertStatus(422);
    }

    public function test_create_with_note(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/contacts/{$a->id}/relations", [
            'related_contact_id' => $b->id,
            'relation_type' => RelationType::Friend->value,
            'note' => 'Met at conference 2026.',
        ])->assertCreated()
            ->assertJsonPath('data.note', 'Met at conference 2026.');
    }

    public function test_duplicate_relation_updates_existing(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/contacts/{$a->id}/relations", [
            'related_contact_id' => $b->id,
            'relation_type' => RelationType::Partner->value,
        ])->assertCreated();

        // Second create with same pair updates — no duplicate row (200 on update, no 201)
        $this->postJson("/api/contacts/{$a->id}/relations", [
            'related_contact_id' => $b->id,
            'relation_type' => RelationType::Mentor->value,
        ])->assertSuccessful();

        $this->assertDatabaseCount('crm_contact_relations', 1);
    }

    public function test_delete_removes_relation_for_both_sides(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        $relation = ContactRelation::create([
            'contact_id' => min($a->id, $b->id),
            'related_contact_id' => max($a->id, $b->id),
            'relation_type' => RelationType::Partner->value,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->deleteJson("/api/contacts/{$a->id}/relations/{$relation->id}")
            ->assertOk();

        $this->assertDatabaseMissing('crm_contact_relations', ['id' => $relation->id]);

        // No longer visible from B's perspective either
        $this->getJson("/api/contacts/{$b->id}/relations")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_manager_cannot_delete_other_users_relation(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $director = User::factory()->create(['role' => Role::Director]);

        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        $relation = ContactRelation::create([
            'contact_id' => min($a->id, $b->id),
            'related_contact_id' => max($a->id, $b->id),
            'relation_type' => RelationType::Partner->value,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/contacts/{$a->id}/relations/{$relation->id}")
            ->assertStatus(403);
    }

    public function test_invalid_relation_type_returns_422(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $b = Contact::factory()->create(['owner_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/contacts/{$a->id}/relations", [
            'related_contact_id' => $b->id,
            'relation_type' => 'NOT_A_VALID_TYPE',
        ])->assertStatus(422);
    }

    // ---- IDOR guard: relation not involving the route contact ----

    public function test_cannot_update_relation_not_involving_route_contact(): void
    {
        // director owns all four contacts; creates a relation between C and D.
        // Then tries to update it via contact A's route (unrelated) — must be 404.
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $c = Contact::factory()->create(['owner_id' => $director->id]);
        $d = Contact::factory()->create(['owner_id' => $director->id]);

        $relation = ContactRelation::create([
            'contact_id' => min($c->id, $d->id),
            'related_contact_id' => max($c->id, $d->id),
            'relation_type' => RelationType::Partner->value,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        // Route uses contact A but relation is between C and D.
        $this->patchJson("/api/contacts/{$a->id}/relations/{$relation->id}", [
            'relation_type' => RelationType::Colleague->value,
        ])->assertNotFound();
    }

    public function test_cannot_delete_relation_not_involving_route_contact(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $a = Contact::factory()->create(['owner_id' => $director->id]);
        $c = Contact::factory()->create(['owner_id' => $director->id]);
        $d = Contact::factory()->create(['owner_id' => $director->id]);

        $relation = ContactRelation::create([
            'contact_id' => min($c->id, $d->id),
            'related_contact_id' => max($c->id, $d->id),
            'relation_type' => RelationType::Partner->value,
            'created_by_id' => $director->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        // Must return 404 — relation does not involve contact A.
        $this->deleteJson("/api/contacts/{$a->id}/relations/{$relation->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('crm_contact_relations', ['id' => $relation->id]);
    }
}
