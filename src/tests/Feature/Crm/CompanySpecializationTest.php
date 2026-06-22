<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for Company.specialization (enum field, single-select).
 */
class CompanySpecializationTest extends TestCase
{
    use RefreshDatabase;

    // ---- store ----

    public function test_company_can_be_created_with_specialization(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', [
            'name' => 'Девелопер ТОО',
            'specialization' => 'developer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.specialization', 'developer');

        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Девелопер ТОО',
            'specialization' => 'developer',
        ]);
    }

    public function test_specialization_is_nullable_on_create(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', ['name' => 'Без специализации']);

        $response->assertCreated()
            ->assertJsonPath('data.specialization', null);
    }

    public function test_invalid_specialization_value_fails_validation(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/companies', [
            'name' => 'Test',
            'specialization' => 'invalid_value',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('specialization');
    }

    // ---- update ----

    public function test_specialization_can_be_updated(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'specialization' => CompanySpecialization::Developer->value,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->patchJson("/api/companies/{$company->id}", [
            'specialization' => 'builder',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.specialization', 'builder');

        $this->assertDatabaseHas('crm_companies', [
            'id' => $company->id,
            'specialization' => 'builder',
        ]);
    }

    public function test_specialization_can_be_cleared(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'specialization' => CompanySpecialization::Partner->value,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/companies/{$company->id}", [
            'specialization' => null,
        ])->assertOk()
            ->assertJsonPath('data.specialization', null);
    }

    // ---- enum ----

    public function test_all_specialization_cases_are_valid(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        foreach (CompanySpecialization::cases() as $case) {
            $response = $this->postJson('/api/companies', [
                'name' => "Company {$case->value}",
                'specialization' => $case->value,
            ]);
            $response->assertCreated()
                ->assertJsonPath('data.specialization', $case->value);
        }
    }

    public function test_specialization_enum_has_labels(): void
    {
        $this->assertNotEmpty(CompanySpecialization::RealEstateAgency->label());
        $this->assertNotEmpty(CompanySpecialization::Developer->label());
        $this->assertNotEmpty(CompanySpecialization::Builder->label());
        $this->assertNotEmpty(CompanySpecialization::Contractor->label());
        $this->assertNotEmpty(CompanySpecialization::Supplier->label());
        $this->assertNotEmpty(CompanySpecialization::Partner->label());
    }
}
