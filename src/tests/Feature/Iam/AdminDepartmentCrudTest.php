<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → Access Control → Departments: org-tree CRUD + members.
 *
 * Covers create / update / delete (with child re-homing + member detach), the
 * re-parent cycle guard, bulk member add/remove, the admin gate and the
 * depth-warning flag.
 */
class AdminDepartmentCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_creates_a_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/departments', ['name' => 'Sales'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sales')
            ->assertJsonPath('data.parent_id', null);

        $this->assertDatabaseHas('departments', ['name' => 'Sales']);
    }

    public function test_admin_creates_a_child_department(): void
    {
        $parent = Department::factory()->create(['name' => 'Company']);
        $this->actingAsAdmin();

        $this->postJson('/api/admin/departments', ['name' => 'Sales', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_admin_renames_and_reparents_a_department(): void
    {
        $a = Department::factory()->create(['name' => 'A']);
        $b = Department::factory()->create(['name' => 'B']);
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/departments/{$b->id}", ['name' => 'B2', 'parent_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'B2')
            ->assertJsonPath('data.parent_id', $a->id);
    }

    public function test_reparent_to_self_is_rejected(): void
    {
        $a = Department::factory()->create();
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/departments/{$a->id}", ['parent_id' => $a->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_reparent_to_descendant_creates_no_cycle(): void
    {
        $root = Department::factory()->create(['name' => 'Root']);
        $child = Department::factory()->create(['name' => 'Child', 'parent_id' => $root->id]);
        $this->actingAsAdmin();

        // Making Root a child of its own descendant Child would create a cycle.
        $this->patchJson("/api/admin/departments/{$root->id}", ['parent_id' => $child->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_delete_rehomes_children_and_detaches_members(): void
    {
        $root = Department::factory()->create(['name' => 'Root']);
        $mid = Department::factory()->create(['name' => 'Mid', 'parent_id' => $root->id]);
        $leaf = Department::factory()->create(['name' => 'Leaf', 'parent_id' => $mid->id]);
        $member = User::factory()->create(['department_id' => $mid->id]);

        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/departments/{$mid->id}")->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $mid->id]);
        // Leaf re-homed to Mid's parent (Root).
        $this->assertDatabaseHas('departments', ['id' => $leaf->id, 'parent_id' => $root->id]);
        // Member detached.
        $this->assertDatabaseHas('users', ['id' => $member->id, 'department_id' => null]);
    }

    public function test_delete_of_root_makes_children_roots(): void
    {
        $root = Department::factory()->create(['name' => 'Root']);
        $child = Department::factory()->create(['name' => 'Child', 'parent_id' => $root->id]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/departments/{$root->id}")->assertNoContent();

        $this->assertDatabaseHas('departments', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_bulk_add_members(): void
    {
        $dept = Department::factory()->create();
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/admin/departments/{$dept->id}/members", ['user_ids' => [$u1->id, $u2->id]])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $u1->id, 'department_id' => $dept->id]);
        $this->assertDatabaseHas('users', ['id' => $u2->id, 'department_id' => $dept->id]);
    }

    public function test_remove_member(): void
    {
        $dept = Department::factory()->create();
        $u = User::factory()->create(['department_id' => $dept->id]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/departments/{$dept->id}/members/{$u->id}")->assertNoContent();

        $this->assertDatabaseHas('users', ['id' => $u->id, 'department_id' => null]);
    }

    public function test_index_returns_counts(): void
    {
        $dept = Department::factory()->create(['name' => 'Sales']);
        Department::factory()->create(['name' => 'Sub', 'parent_id' => $dept->id]);
        User::factory()->create(['department_id' => $dept->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/departments')->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'Sales');
        $this->assertSame(1, $row['members_count']);
        $this->assertSame(1, $row['children_count']);
    }

    public function test_deep_tree_flags_depth_warning(): void
    {
        $this->actingAsAdmin();

        // Build 5 levels (root=1) — exceeds the soft threshold of 4.
        $parentId = null;
        $lastId = null;
        for ($i = 1; $i <= 5; $i++) {
            $payload = ['name' => "L{$i}"];
            if ($parentId !== null) {
                $payload['parent_id'] = $parentId;
            }
            $response = $this->postJson('/api/admin/departments', $payload)->assertCreated();
            $lastId = $response->json('data.id');
            $parentId = $lastId;
        }

        // The 5th-level node should carry the depth warning.
        $response = $this->postJson('/api/admin/departments', ['name' => 'L6', 'parent_id' => $lastId])
            ->assertCreated();
        $this->assertTrue($response->json('meta.depth_warning'));
    }

    public function test_manager_cannot_write_departments(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/departments', ['name' => 'X'])->assertForbidden();
    }
}
