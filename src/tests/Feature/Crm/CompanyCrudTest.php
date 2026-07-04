<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
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

    /**
     * Data-Layer-Audit-2026-07 §3.5: the list uses the lean CompanyListResource —
     * the fields the table renders (name/country/city/category/tags/engagement/
     * owner/…) stay, the card-only legal/bank/requisite fields + notes +
     * extra_fields are dropped from every row. The card path keeps them all.
     */
    public function test_list_row_is_lean_and_card_row_is_full(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $company = Company::factory()->create([
            'name' => 'Acme Corp',
            'legal_name' => 'Acme LLC',
            'tax_id' => '7700000000',
            'bank' => 'Big Bank',
            'account' => '40702810000000000000',
            'notes' => 'internal note',
            'country_code' => 'kz',
        ]);

        // LIST — lean: display fields present, card-only requisites absent.
        $row = $this->getJson('/api/companies')->assertOk()->json('data.0');
        $this->assertSame('Acme Corp', $row['name']);
        $this->assertSame('kz', $row['country_code']);
        $this->assertArrayHasKey('category_code', $row);
        $this->assertArrayHasKey('engagement_tier', $row);
        $this->assertArrayHasKey('client_status', $row);
        foreach (['legal_name', 'tax_id', 'bank', 'account', 'notes', 'extra_fields', 'address', 'requisites', 'contact_links'] as $fat) {
            $this->assertArrayNotHasKey($fat, $row, "list row must not carry {$fat}");
        }

        // CARD — full: the requisites the list dropped are back.
        $card = $this->getJson("/api/companies/{$company->id}")->assertOk()->json('data');
        $this->assertSame('Acme LLC', $card['legal_name']);
        $this->assertSame('7700000000', $card['tax_id']);
        $this->assertArrayHasKey('bank', $card);
        $this->assertArrayHasKey('extra_fields', $card);
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

    /**
     * Quick win 6b: CompanyService::create() must write a LogAction::Created
     * entity-log row (mirrors ContactService::create) so the company's
     * timeline shows a creation event instead of starting blank.
     */
    public function test_create_company_writes_created_entity_log(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', [
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
        ])->assertCreated();

        $companyId = $response->json('data.id');

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'company',
            'subject_id' => $companyId,
            'action' => 'created',
            'actor_id' => $user->id,
        ]);
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

    /**
     * Quick win 6c: category_code is enum-cast (CategoryCode::class). Before
     * scalarize() was ported into CompanyService::diffLoggedFields (mirroring
     * ContactService), comparing the enum-cast $original value against a raw
     * string $data value ALWAYS mismatched even when the category did not
     * actually change — producing a phantom data_changed log entry any time
     * category_code was included unchanged in an update payload (e.g. from an
     * internal caller that resends the full record, such as the category
     * recalculation job). Called directly at the service level because
     * category_code is not part of UpdateCompanyRequest's public write surface
     * (it is system-recalculated), so this diff path is only reachable
     * in-process.
     */
    public function test_resending_unchanged_category_code_does_not_emit_phantom_change(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'category_code' => CategoryCode::M,
            'name' => 'Old Name',
        ]);

        $service = $this->app->make(CompanyService::class);

        // Resend category_code unchanged (raw string, as a recalculation job would)
        // alongside a real name change — category_code must not appear in the diff.
        $service->update($company, [
            'name' => 'New Name',
            'category_code' => 'M',
        ], $user);

        $log = EntityLog::query()
            ->where('subject_type', 'company')
            ->where('subject_id', $company->id)
            ->where('action', 'data_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $changedFields = collect($log->meta['changes'] ?? [])->pluck('field')->all();

        $this->assertContains('name', $changedFields);
        $this->assertNotContains('category_code', $changedFields, 'category_code must not appear as changed when it was resent unchanged');
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

    /**
     * BLOCKER: a company with an OPEN deal must not be soft-deletable —
     * deals.company_id is NOT NULL, so soft-deleting the company would leave
     * the deal's card pointing at an invisible (trashed) company row.
     * "Open" mirrors DealService::aggregateForCompany — stage flags
     * (is_won=false AND is_lost=false) are the sole arbiter, not closed_at.
     */
    public function test_cannot_delete_company_with_open_deal(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($openStage)->create([
            'company_id' => $company->id,
            'owner_user_id' => $user->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/companies/{$company->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('crm_companies', ['id' => $company->id, 'deleted_at' => null]);
    }

    /**
     * Regression guard for the AMO edge-case (mirrors CompanyKpiTest): a deal on
     * an open (non-terminal) stage with a stale closed_at value must STILL block
     * deletion — closed_at is not the arbiter, stage flags are.
     */
    public function test_cannot_delete_company_with_open_deal_that_has_stale_closed_at(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($openStage)->create([
            'company_id' => $company->id,
            'owner_user_id' => $user->id,
            'closed_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/companies/{$company->id}")
            ->assertStatus(422);
    }

    /**
     * A company whose only deals are WON or LOST (closed, per stage flags) can
     * still be deleted — the guard only blocks OPEN deals.
     */
    public function test_can_delete_company_with_only_closed_deals(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $wonStage = PipelineStage::factory()->create(['is_won' => true, 'is_lost' => false]);
        $lostStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => true]);
        Deal::factory()->inStage($wonStage)->create(['company_id' => $company->id, 'owner_user_id' => $user->id]);
        Deal::factory()->inStage($lostStage)->create(['company_id' => $company->id, 'owner_user_id' => $user->id]);

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
