<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for HoldingController (B5).
 * Tests: show tree, attach, detach, cycle detection.
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

    public function test_show_marks_you_are_here(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $parent = Company::factory()->create(['owner_user_id' => $user->id]);
        $child = Company::factory()->create([
            'owner_user_id' => $user->id,
            'holding_id' => $parent->id,
            'holding_role' => HoldingRole::Subsidiary,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Viewing child: root company should NOT be you_are_here; child in children should be
        $resp = $this->getJson("/api/companies/{$child->id}/holding")
            ->assertOk();

        // Root should be parent; you_are_here on root node = false
        $this->assertFalse($resp->json('data.company.you_are_here'));

        // First child in children array should be you_are_here
        $children = $resp->json('data.children');
        $this->assertCount(1, $children);
        $this->assertTrue($children[0]['company']['you_are_here']);
    }
}
