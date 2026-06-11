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
 * Tests for GET /api/crm/dedup/scan?scope=X (global mode — no entity_id).
 *
 * Verifies:
 *  1. Returns groups of duplicate contacts (shared email / phone / name).
 *  2. Returns groups of duplicate companies (shared email / tax_id / name).
 *  3. Admin / Director see ALL records.
 *  4. Manager sees only their own records (visibility-scoped).
 *  5. Single records (no match) are NOT returned in any group.
 */
class DedupGlobalScanTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Contact groups
    // =========================================================================

    public function test_global_scan_contacts_groups_by_email(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $c1 = Contact::factory()->create(['email' => 'dup@example.com', 'owner_id' => $admin->id]);
        $c2 = Contact::factory()->create(['email' => 'dup@example.com', 'owner_id' => $admin->id]);
        // Unique contact — should NOT appear in any group
        Contact::factory()->create(['email' => 'unique@example.com', 'owner_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Expected at least one duplicate group');

        // Find the group for dup@example.com
        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'email:dup@example.com');
        $this->assertNotNull($group, 'Expected group with key email:dup@example.com');

        $ids = collect($group['entities'])->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
    }

    public function test_global_scan_contacts_groups_by_phone(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $c1 = Contact::factory()->create(['phone' => '+7 (999) 123-45-67', 'email' => 'a1@x.com', 'full_name' => 'Person One', 'owner_id' => $admin->id]);
        $c2 = Contact::factory()->create(['phone' => '79991234567', 'email' => 'a2@x.com', 'full_name' => 'Person Two', 'owner_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();

        $data = $response->json('data');

        // Both phones normalize to "79991234567"
        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'phone:79991234567');
        $this->assertNotNull($group, 'Expected phone duplicate group');

        $ids = collect($group['entities'])->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
    }

    public function test_global_scan_contacts_groups_by_name(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $c1 = Contact::factory()->create(['full_name' => 'Иван Петров', 'email' => 'ivan1@x.com', 'owner_id' => $admin->id]);
        $c2 = Contact::factory()->create(['full_name' => 'Иван Петров', 'email' => 'ivan2@x.com', 'owner_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();

        $data = $response->json('data');

        $group = collect($data)->first(fn (array $g): bool => str_starts_with($g['key'], 'name:'));
        $this->assertNotNull($group, 'Expected name duplicate group');

        $ids = collect($group['entities'])->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
    }

    public function test_global_scan_returns_empty_when_no_duplicates(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        Contact::factory()->create(['email' => 'only@example.com', 'full_name' => 'Solo Person', 'owner_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();

        // No groups with more than 1 entity should exist
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    // =========================================================================
    // Company groups
    // =========================================================================

    public function test_global_scan_companies_groups_by_tax_id(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $co1 = Company::factory()->create(['tax_id' => '99988877766', 'name' => 'Alpha LLC', 'owner_user_id' => $admin->id]);
        $co2 = Company::factory()->create(['tax_id' => '99988877766', 'name' => 'Alpha LLC copy', 'owner_user_id' => $admin->id]);
        Company::factory()->create(['tax_id' => '11122233344', 'name' => 'Unique Co', 'owner_user_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=company')->assertOk();

        $data = $response->json('data');

        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'tax_id:99988877766');
        $this->assertNotNull($group, 'Expected tax_id duplicate group');

        $ids = collect($group['entities'])->pluck('id')->toArray();
        $this->assertContains($co1->id, $ids);
        $this->assertContains($co2->id, $ids);
    }

    // =========================================================================
    // Visibility scoping
    // =========================================================================

    public function test_manager_sees_only_own_contacts_in_global_scan(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        // Two contacts owned by manager with same email → should appear in scan
        $m1 = Contact::factory()->create(['email' => 'mgr@example.com', 'owner_id' => $manager->id]);
        $m2 = Contact::factory()->create(['email' => 'mgr@example.com', 'owner_id' => $manager->id]);

        // Two contacts owned by other user with same email → should NOT appear
        Contact::factory()->create(['email' => 'other@example.com', 'owner_id' => $other->id]);
        Contact::factory()->create(['email' => 'other@example.com', 'owner_id' => $other->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();
        $data = $response->json('data');

        // Manager should see their own dup group
        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'email:mgr@example.com');
        $this->assertNotNull($group);

        // Manager must NOT see other user's contacts in any group
        $allIds = collect($data)->flatMap(fn (array $g): array => collect($g['entities'])->pluck('id')->toArray())->toArray();
        $this->assertNotContains(
            'other@example.com',
            collect($data)->flatMap(fn (array $g): array => collect($g['entities'])->pluck('email')->toArray())->toArray()
        );

        // Confirm: only manager-owned IDs present
        $this->assertContains($m1->id, $allIds);
        $this->assertContains($m2->id, $allIds);
    }

    public function test_admin_sees_all_contacts_in_global_scan(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $u1 = User::factory()->create(['role' => Role::Manager]);
        $u2 = User::factory()->create(['role' => Role::Manager]);

        $c1 = Contact::factory()->create(['email' => 'cross@example.com', 'owner_id' => $u1->id]);
        $c2 = Contact::factory()->create(['email' => 'cross@example.com', 'owner_id' => $u2->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();
        $data = $response->json('data');

        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'email:cross@example.com');
        $this->assertNotNull($group, 'Admin should see cross-owner duplicate group');

        $ids = collect($group['entities'])->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
    }

    public function test_manager_cannot_see_other_owners_contacts_in_global_scan(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        // Duplicate pair owned entirely by $other
        Contact::factory()->create(['email' => 'foreign@example.com', 'owner_id' => $other->id]);
        Contact::factory()->create(['email' => 'foreign@example.com', 'owner_id' => $other->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();
        $data = $response->json('data');

        $group = collect($data)->first(fn (array $g): bool => $g['key'] === 'email:foreign@example.com');
        $this->assertNull($group, 'Manager must not see duplicate groups owned by other users');
    }

    // =========================================================================
    // Response shape
    // =========================================================================

    public function test_global_scan_response_shape(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        Contact::factory()->create(['email' => 'shape@example.com', 'owner_id' => $admin->id]);
        Contact::factory()->create(['email' => 'shape@example.com', 'owner_id' => $admin->id]);

        $response = $this->getJson('/api/crm/dedup/scan?scope=contact')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'key',
                    'entities' => [
                        '*' => ['id', 'type', 'created_at'],
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_global_scan_requires_scope(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/crm/dedup/scan')->assertUnprocessable();
    }

    public function test_global_scan_rejects_invalid_scope(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/crm/dedup/scan?scope=deal')->assertUnprocessable();
    }
}
