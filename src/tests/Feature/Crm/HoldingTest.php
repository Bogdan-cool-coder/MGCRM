<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\HoldingService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for HoldingController (B5) + HoldingService (N+1 fix backlog-2).
 *
 * Contract (focal-centric — fixed in BUG-HOLDING-1):
 *   data.company      = the company being viewed (you_are_here: true)
 *   data.ancestors[]  = HoldingCompanyNode[] root-first → direct parent last (you_are_here: false)
 *   data.children[]   = HoldingCompanyNode[] direct subsidiaries of focal (flat, you_are_here: false)
 */
class HoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_empty_tree_for_solo_company(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/companies/{$company->id}/holding")
            ->assertOk()
            ->assertJsonStructure(['data' => ['company', 'ancestors', 'children']]);

        // Focal company with no parent and no children
        $this->assertSame($company->id, $resp->json('data.company.id'));
        $this->assertTrue($resp->json('data.company.you_are_here'));
        $this->assertSame([], $resp->json('data.ancestors'));
        $this->assertSame([], $resp->json('data.children'));
    }

    public function test_attach_sets_parent_and_tree_returned(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create(['owner_user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->postJson("/api/companies/{$child->id}/holding", [
            'parent_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary->value,
        ])->assertOk();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $child->id,
            'holding_id' => $parent->id,
        ]);
    }

    public function test_detach_clears_parent(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/companies/{$child->id}/holding")
            ->assertOk();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $child->id,
            'holding_id' => null,
        ]);
    }

    public function test_cycle_detection_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $a = Company::factory()->create(['owner_user_id' => $user->id]);
        $b = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $a->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        // A is parent of B → trying to make B parent of A should 422
        $this->postJson("/api/companies/{$a->id}/holding", [
            'parent_id' => $b->id,
            'holding_role' => HoldingRole::Subsidiary->value,
        ])->assertStatus(422)
            ->assertJsonPath('error', 'holding_cycle');
    }

    public function test_self_parent_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/holding", [
            'parent_id' => $company->id,
            'holding_role' => HoldingRole::Subsidiary->value,
        ])->assertStatus(422);
    }

    /**
     * Viewing a child company: data.company = child (focal), ancestors = [parent], children = [].
     */
    public function test_show_marks_focal_as_you_are_here(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Viewing child: data.company = child (you_are_here: true)
        $resp = $this->getJson("/api/companies/{$child->id}/holding")
            ->assertOk();

        $this->assertSame($child->id, $resp->json('data.company.id'));
        $this->assertTrue($resp->json('data.company.you_are_here'));

        // Ancestor = [parent]
        $ancestors = $resp->json('data.ancestors');
        $this->assertCount(1, $ancestors);
        $this->assertSame($parent->id, $ancestors[0]['id']);
        $this->assertFalse($ancestors[0]['you_are_here']);

        // Child has no subsidiaries
        $this->assertSame([], $resp->json('data.children'));
    }

    /**
     * Viewing a parent company: data.company = parent (focal), ancestors = [],
     * children = flat HoldingCompanyNode[] of direct subsidiaries.
     */
    public function test_show_from_parent_returns_flat_children(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child1 = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        $child2 = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/companies/{$parent->id}/holding")
            ->assertOk();

        // Focal is root/parent itself
        $this->assertSame($parent->id, $resp->json('data.company.id'));
        $this->assertTrue($resp->json('data.company.you_are_here'));
        $this->assertSame([], $resp->json('data.ancestors'));

        // Children = flat list of direct subsidiaries (HoldingCompanyNode[], not nested)
        $children = $resp->json('data.children');
        $this->assertCount(2, $children);
        $childIds = array_column($children, 'id');
        $this->assertContains($child1->id, $childIds);
        $this->assertContains($child2->id, $childIds);

        // Each child node is a flat HoldingCompanyNode (no nested 'company' key)
        foreach ($children as $node) {
            $this->assertArrayHasKey('id', $node);
            $this->assertArrayHasKey('name', $node);
            $this->assertArrayHasKey('you_are_here', $node);
            $this->assertFalse($node['you_are_here']);
            // NOT the old nested shape — no 'company' sub-key
            $this->assertArrayNotHasKey('company', $node);
        }
    }

    /**
     * 4-level deep tree (grandparent→parent→focal→leaf).
     * Viewing focal: ancestors=[gp, parent], company=focal, children=[leaf] (flat).
     */
    public function test_deep_tree_four_levels_focal_centric(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);

        $gp = Company::factory()->create(['owner_user_id' => $user->id]);
        $parent = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $gp->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        $focal = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        $leaf = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $focal->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/companies/{$focal->id}/holding")
            ->assertOk()
            ->assertJsonStructure(['data' => ['company', 'ancestors', 'children']]);

        // Focal company at top-level
        $this->assertSame($focal->id, $resp->json('data.company.id'));
        $this->assertTrue($resp->json('data.company.you_are_here'));

        // Ancestors: root-first → direct parent last
        $ancestors = $resp->json('data.ancestors');
        $this->assertCount(2, $ancestors);
        $this->assertSame($gp->id, $ancestors[0]['id']);
        $this->assertSame($parent->id, $ancestors[1]['id']);
        foreach ($ancestors as $anc) {
            $this->assertFalse($anc['you_are_here']);
        }

        // Children: flat HoldingCompanyNode[] — only direct children of focal
        $children = $resp->json('data.children');
        $this->assertCount(1, $children);
        $this->assertSame($leaf->id, $children[0]['id']);
        $this->assertFalse($children[0]['you_are_here']);
        // Flat — no nested 'company' sub-key
        $this->assertArrayNotHasKey('company', $children[0]);
    }

    /**
     * Backlog-2: verify no N+1 — the full group loads in a bounded number of
     * queries regardless of the number of nodes.
     * For a 3-level, 6-node tree total query count must be ≤ 8.
     */
    public function test_build_tree_no_n_plus_one(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);

        // Build: root → [c1, c2] → c1 has [c1a, c1b], c2 has [c2a]  (6 nodes, 3 levels)
        $root = Company::factory()->create(['owner_user_id' => $user->id]);

        $c1 = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $root->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        $c2 = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $root->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $c1->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $c1->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $c2->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        // Extra solo company — must NOT appear in tree
        Company::factory()->create(['owner_user_id' => $user->id]);

        $service = app(HoldingService::class);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $tree = $service->buildTree($root);

        // Root is the focal company (viewing root itself)
        $this->assertSame($root->id, $tree['company']['id']);
        $this->assertTrue($tree['company']['you_are_here']);

        // Direct children of root = [c1, c2]
        $this->assertCount(2, $tree['children']);

        // At most 8 queries for a 3-level, 6-node tree
        $this->assertLessThanOrEqual(8, $queryCount, "Expected ≤8 queries for a 3-level tree, got {$queryCount}");
    }

    /**
     * Viewing from a leaf — focal=leaf, ancestors=[root, mid], children=[].
     */
    public function test_view_from_leaf_focal_centric(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);

        $root = Company::factory()->create(['owner_user_id' => $user->id]);
        $mid = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $root->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);
        $leaf = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $mid->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson("/api/companies/{$leaf->id}/holding")->assertOk();

        // Focal = leaf (you_are_here: true)
        $this->assertSame($leaf->id, $resp->json('data.company.id'));
        $this->assertTrue($resp->json('data.company.you_are_here'));

        // Ancestors = [root, mid] — root first
        $ancestors = $resp->json('data.ancestors');
        $this->assertCount(2, $ancestors);
        $this->assertSame($root->id, $ancestors[0]['id']);
        $this->assertSame($mid->id, $ancestors[1]['id']);
        foreach ($ancestors as $anc) {
            $this->assertFalse($anc['you_are_here']);
        }

        // Leaf has no children
        $this->assertSame([], $resp->json('data.children'));
    }

    // ---- A-Холдинг: role persistence and default ----

    public function test_attach_with_each_role_persists_correct_role(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($user, ['*']);

        foreach (HoldingRole::cases() as $role) {
            $parent = Company::factory()->create(['owner_user_id' => $user->id]);
            $child = Company::factory()->create(['owner_user_id' => $user->id]);

            $this->postJson("/api/companies/{$child->id}/holding", [
                'parent_id' => $parent->id,
                'holding_role' => $role->value,
            ])->assertOk();

            $this->assertDatabaseHas('crm_companies', [
                'id' => $child->id,
                'holding_id' => $parent->id,
                'holding_role' => $role->value,
            ]);
        }
    }

    public function test_attach_without_role_defaults_to_subsidiary(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$child->id}/holding", [
            'parent_id' => $parent->id,
            // holding_role omitted → defaults to subsidiary
        ])->assertOk();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $child->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary->value,
        ]);
    }

    public function test_attach_with_invalid_role_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$child->id}/holding", [
            'parent_id' => $parent->id,
            'holding_role' => 'not_a_valid_role',
        ])->assertStatus(422);
    }
}
