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
 * Settings → department directory: GET /api/admin/departments.
 *
 * Read-only list that feeds the "add user" form department Select. Same
 * admin/director gate (`admin-write`) as the sibling user-management list.
 */
class AdminDepartmentListTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_lists_departments(): void
    {
        $sales = Department::factory()->create(['name' => 'Sales']);
        $legal = Department::factory()->create(['name' => 'Legal', 'parent_id' => $sales->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/departments')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                ['id', 'name', 'parent_id', 'manager_id'],
            ],
        ]);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Sales'));
        $this->assertTrue($names->contains('Legal'));

        $legalRow = collect($response->json('data'))->firstWhere('name', 'Legal');
        $this->assertSame($sales->id, $legalRow['parent_id']);
    }

    public function test_list_is_ordered_by_name(): void
    {
        Department::factory()->create(['name' => 'Zeta']);
        Department::factory()->create(['name' => 'Alpha']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/departments')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame('Alpha', $names[0]);
        $this->assertSame('Zeta', $names[1]);
    }

    public function test_director_can_list_departments(): void
    {
        Department::factory()->create(['name' => 'Finance']);
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/admin/departments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Finance');
    }

    public function test_manager_cannot_list_departments(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/departments')->assertForbidden();
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/admin/departments')->assertStatus(401);
    }
}
