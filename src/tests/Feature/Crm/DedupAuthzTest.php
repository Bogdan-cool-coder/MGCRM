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
 * Authorization tests for DedupController.
 *
 * Rules (per ContactPolicy / CompanyPolicy):
 *  - scan:    user must be able to view the entity_id record
 *  - merge:   user must be able to update master AND all duplicate IDs
 *  - dismiss: user must be able to view both entity IDs
 *
 * A Manager can only access records they own (owner_id / owner_user_id).
 * Admin / Director bypass ownership checks.
 */
class DedupAuthzTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // scan
    // =========================================================================

    public function test_manager_can_scan_own_contact(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $manager->id]);

        // Create another contact with same email so scan returns results
        Contact::factory()->create([
            'email' => $contact->email,
            'owner_id' => $manager->id,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson("/api/crm/dedup/scan?scope=contact&entity_id={$contact->id}")
            ->assertOk();
    }

    public function test_manager_cannot_scan_foreign_contact(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/crm/dedup/scan?scope=contact&entity_id={$contact->id}")
            ->assertForbidden();
    }

    public function test_manager_can_scan_own_company(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $manager->id]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson("/api/crm/dedup/scan?scope=company&entity_id={$company->id}")
            ->assertOk();
    }

    public function test_manager_cannot_scan_foreign_company(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);

        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/crm/dedup/scan?scope=company&entity_id={$company->id}")
            ->assertForbidden();
    }

    public function test_admin_can_scan_any_contact(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/crm/dedup/scan?scope=contact&entity_id={$contact->id}")
            ->assertOk();
    }

    public function test_scan_returns_404_for_nonexistent_entity(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/crm/dedup/scan?scope=contact&entity_id=99999')
            ->assertNotFound();
    }

    // =========================================================================
    // merge
    // =========================================================================

    public function test_manager_can_merge_own_contacts(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $master = Contact::factory()->create(['owner_id' => $manager->id]);
        $dup = Contact::factory()->create(['owner_id' => $manager->id]);

        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();
    }

    public function test_manager_cannot_merge_contacts_if_master_is_foreign(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $master = Contact::factory()->create(['owner_id' => $owner->id]);
        $dup = Contact::factory()->create(['owner_id' => $other->id]);

        Sanctum::actingAs($other, ['*']);

        // master belongs to $owner — $other cannot update it
        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertForbidden();
    }

    public function test_manager_cannot_merge_contacts_if_duplicate_is_foreign(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $master = Contact::factory()->create(['owner_id' => $other->id]);
        $dup = Contact::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($other, ['*']);

        // dup belongs to $owner — $other cannot update it
        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertForbidden();
    }

    public function test_manager_cannot_merge_companies_if_master_is_foreign(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $master = Company::factory()->create(['owner_user_id' => $owner->id]);
        $dup = Company::factory()->create(['owner_user_id' => $other->id]);

        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertForbidden();
    }

    public function test_admin_can_merge_any_contacts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner1 = User::factory()->create(['role' => Role::Manager]);
        $owner2 = User::factory()->create(['role' => Role::Manager]);
        $master = Contact::factory()->create(['owner_id' => $owner1->id]);
        $dup = Contact::factory()->create(['owner_id' => $owner2->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();
    }

    public function test_director_can_merge_any_companies(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $owner1 = User::factory()->create(['role' => Role::Manager]);
        $owner2 = User::factory()->create(['role' => Role::Manager]);
        $master = Company::factory()->create(['owner_user_id' => $owner1->id]);
        $dup = Company::factory()->create(['owner_user_id' => $owner2->id]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'company',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])->assertOk();
    }

    public function test_merge_returns_404_for_nonexistent_master(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $dup = Contact::factory()->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => 99999,
            'duplicate_ids' => [$dup->id],
        ])->assertNotFound();
    }

    // =========================================================================
    // dismiss
    // =========================================================================

    public function test_manager_can_dismiss_own_contacts(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $c1 = Contact::factory()->create(['owner_id' => $manager->id]);
        $c2 = Contact::factory()->create(['owner_id' => $manager->id]);

        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'contact',
            'entity_a_id' => $c1->id,
            'entity_b_id' => $c2->id,
        ])->assertOk();
    }

    public function test_manager_cannot_dismiss_if_entity_a_is_foreign(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $foreign = Contact::factory()->create(['owner_id' => $owner->id]);
        $own = Contact::factory()->create(['owner_id' => $other->id]);

        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'contact',
            'entity_a_id' => $foreign->id,
            'entity_b_id' => $own->id,
        ])->assertForbidden();
    }

    public function test_manager_cannot_dismiss_if_entity_b_is_foreign(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $own = Contact::factory()->create(['owner_id' => $other->id]);
        $foreign = Contact::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'contact',
            'entity_a_id' => $own->id,
            'entity_b_id' => $foreign->id,
        ])->assertForbidden();
    }

    public function test_admin_can_dismiss_any_companies(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner1 = User::factory()->create(['role' => Role::Manager]);
        $owner2 = User::factory()->create(['role' => Role::Manager]);
        $co1 = Company::factory()->create(['owner_user_id' => $owner1->id]);
        $co2 = Company::factory()->create(['owner_user_id' => $owner2->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'company',
            'entity_a_id' => $co1->id,
            'entity_b_id' => $co2->id,
        ])->assertOk();
    }

    public function test_dismiss_returns_404_for_nonexistent_entity(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['owner_id' => $admin->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'contact',
            'entity_a_id' => $contact->id,
            'entity_b_id' => 99999,
        ])->assertNotFound();
    }
}
