<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_manager_can_list_companies(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Company::factory()->count(3)->create(['owner_user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/companies')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_unauthenticated_cannot_list_companies(): void
    {
        $this->getJson('/api/companies')->assertUnauthorized();
    }

    // ---- store ----

    public function test_manager_can_create_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', [
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Acme Corp');
        $this->assertDatabaseHas('crm_companies', ['name' => 'Acme Corp']);
    }

    public function test_company_name_is_required(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/companies', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('name');
    }

    // ---- show ----

    public function test_owner_can_view_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_foreign_manager_gets_403_on_company(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_any_company(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk();
    }

    // ---- update ----

    public function test_owner_can_update_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/companies/{$company->id}", ['name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_foreign_manager_cannot_update_company(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        // in-controller authorize now enforces this explicitly (CONVENTION fix)
        $this->patchJson("/api/companies/{$company->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    // ---- destroy ----

    public function test_owner_can_delete_own_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/companies/{$company->id}")
            ->assertOk();

        $this->assertSoftDeleted('crm_companies', ['id' => $company->id]);
    }

    // ---- Company Type FK resolves label ----

    public function test_company_resource_includes_type_label(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        // Use firstOrCreate to handle the seeded 'Партнёр' that migration inserts
        $type = CompanyType::firstOrCreate(
            ['name' => 'Партнёр'],
            ['sort_order' => 4, 'is_active' => true],
        );
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'company_type_id' => $type->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.company_type.name', 'Партнёр');
    }
}
