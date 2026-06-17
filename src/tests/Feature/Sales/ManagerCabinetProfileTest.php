<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/me/profile (S1.8).
 */
class ManagerCabinetProfileTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/me/profile')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Profile fields
    // -------------------------------------------------------------------------

    public function test_profile_returns_own_profile(): void
    {
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'job_title' => 'Менеджер по продажам',
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $manager->id)
            ->assertJsonPath('data.full_name', $manager->full_name)
            ->assertJsonPath('data.email', $manager->email)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.job_title', 'Менеджер по продажам');
    }

    public function test_profile_includes_department_name(): void
    {
        $dept = Department::factory()->create(['name' => 'Отдел продаж']);
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('data.department_id', $dept->id)
            ->assertJsonPath('data.department_name', 'Отдел продаж');
    }

    public function test_profile_includes_manager_name(): void
    {
        $boss = User::factory()->create(['full_name' => 'Иванов Директор']);
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'manager_id' => $boss->id,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('data.manager_id', $boss->id)
            ->assertJsonPath('data.manager_name', 'Иванов Директор');
    }

    public function test_profile_includes_subordinates_count(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);

        // 2 active subordinates
        User::factory()->count(2)->create([
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        // 1 inactive — must NOT be counted
        User::factory()->create([
            'manager_id' => $manager->id,
            'is_active' => false,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('data.subordinates_count', 2);
    }

    public function test_profile_no_department_returns_nulls(): void
    {
        $manager = User::factory()->create([
            'role' => Role::Manager,
            'department_id' => null,
            'manager_id' => null,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.department_name', null)
            ->assertJsonPath('data.manager_id', null)
            ->assertJsonPath('data.manager_name', null);
    }

    // -------------------------------------------------------------------------
    // Visibility scope
    // -------------------------------------------------------------------------

    public function test_manager_cannot_see_other_user_profile(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/me/profile?user_id='.$other->id)
            ->assertForbidden();
    }

    public function test_director_can_see_any_user_profile(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Другой Менеджер']);
        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/me/profile?user_id='.$manager->id)
            ->assertOk()
            ->assertJsonPath('data.id', $manager->id)
            ->assertJsonPath('data.full_name', 'Другой Менеджер');
    }
}
