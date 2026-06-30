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
 * Department members — GET /api/admin/departments/{department}/members (task #8).
 *
 * The Access Control member-count badge links here; the route was previously
 * unregistered (the SPA's accessControlApi.getDepartmentMembers 404'd). Covers:
 * a department returns exactly its N members, an empty department returns an
 * empty array, members of OTHER departments are excluded, the admin gate, and
 * 404 for an unknown department.
 */
class AdminDepartmentMembersTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_returns_members_of_the_department(): void
    {
        $dept = Department::factory()->create(['name' => 'Sales']);
        $other = Department::factory()->create(['name' => 'Legal']);

        User::factory()->count(3)->create(['department_id' => $dept->id]);
        // A user in a different department must NOT appear.
        User::factory()->create(['department_id' => $other->id, 'full_name' => 'Other Dept']);
        // A user with no department must NOT appear either.
        User::factory()->create(['department_id' => null, 'full_name' => 'No Dept']);

        $this->actingAsAdmin();

        $response = $this->getJson("/api/admin/departments/{$dept->id}/members")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $response->assertJsonStructure([
            'data' => [
                ['id', 'full_name', 'email', 'role'],
            ],
        ]);

        $names = collect($response->json('data'))->pluck('full_name');
        $this->assertFalse($names->contains('Other Dept'));
        $this->assertFalse($names->contains('No Dept'));
    }

    public function test_empty_department_returns_empty_array(): void
    {
        $dept = Department::factory()->create();
        $this->actingAsAdmin();

        $this->getJson("/api/admin/departments/{$dept->id}/members")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertExactJson(['data' => []]);
    }

    public function test_member_count_matches_directory_badge(): void
    {
        $dept = Department::factory()->create();
        User::factory()->count(9)->create(['department_id' => $dept->id]);
        $this->actingAsAdmin();

        $this->getJson("/api/admin/departments/{$dept->id}/members")
            ->assertOk()
            ->assertJsonCount(9, 'data');
    }

    public function test_manager_cannot_view_members(): void
    {
        $dept = Department::factory()->create();
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson("/api/admin/departments/{$dept->id}/members")
            ->assertForbidden();
    }

    public function test_members_requires_authentication(): void
    {
        $dept = Department::factory()->create();

        $this->getJson("/api/admin/departments/{$dept->id}/members")
            ->assertStatus(401);
    }

    public function test_unknown_department_yields_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/departments/999999/members')
            ->assertNotFound();
    }
}
