<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for CompanyBulkController (B7).
 * Tests: assign_responsible, set_tags, 403 for foreign company.
 */
class BulkCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_assign_responsible(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $responsible = User::factory()->create(['role' => Role::Manager]);

        $a = Company::factory()->create(['owner_user_id' => $director->id]);
        $b = Company::factory()->create(['owner_user_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/companies/bulk', [
            'company_ids' => [$a->id, $b->id],
            'operation' => 'assign_responsible',
            'responsible_user_id' => $responsible->id,
        ])->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->assertDatabaseHas('crm_companies', ['id' => $a->id, 'responsible_user_id' => $responsible->id]);
    }

    public function test_bulk_set_tags(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create(['owner_user_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->patchJson('/api/companies/bulk', [
            'company_ids' => [$company->id],
            'operation' => 'set_tags',
            'tags' => ['enterprise', 'vip'],
        ])->assertOk()
            ->assertJsonPath('data.processed', 1);

        $company->refresh();
        $this->assertContains('enterprise', $company->tags);
    }

    public function test_bulk_403_for_foreign_company(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $foreignCompany = Company::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'responsible_user_id' => null,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->patchJson('/api/companies/bulk', [
            'company_ids' => [$foreignCompany->id],
            'operation' => 'set_tags',
            'tags' => ['test'],
        ])->assertStatus(403);
    }

    public function test_bulk_delete(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $company = Company::factory()->create(['owner_user_id' => $director->id]);

        Sanctum::actingAs($director, ['*']);

        $this->deleteJson('/api/companies/bulk', [
            'company_ids' => [$company->id],
        ])->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertSoftDeleted('crm_companies', ['id' => $company->id]);
    }
}
