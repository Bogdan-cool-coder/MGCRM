<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MAJOR #1 regression tests — Custom-field validation on the company write path.
 *
 * Ensures that:
 * 1. When no active defs exist, extra_fields is stored free-form (backward compat).
 * 2. When a def exists, a valid value is accepted and coerced.
 * 3. When a def exists, an unknown key is rejected (422).
 * 4. On update, clearing extra_fields (null/[]) resets it.
 */
class CompanyCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        return Company::factory()->create(array_merge(['owner_user_id' => $this->admin->id], $attrs));
    }

    private function makeTextDef(string $code = 'notes_extra'): CustomFieldDef
    {
        return CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => $code,
            'label' => 'Extra Notes',
            'field_type' => 'text',
            'is_active' => true,
        ]);
    }

    private function makeNumberDef(string $code = 'score'): CustomFieldDef
    {
        return CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => $code,
            'label' => 'Score',
            'field_type' => 'number',
            'is_active' => true,
        ]);
    }

    // ── create path ──────────────────────────────────────────────────────────

    /**
     * When no defs exist, extra_fields is stored as-is (backward compatibility).
     */
    public function test_create_stores_extra_fields_free_form_when_no_defs(): void
    {
        // No CustomFieldDef rows in DB — free-form pass-through expected.
        $response = $this->postJson('/api/companies', [
            'name' => 'Acme Co',
            'extra_fields' => ['arbitrary_key' => 'some value'],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('crm_companies', ['name' => 'Acme Co']);
        $company = Company::where('name', 'Acme Co')->first();
        $this->assertSame('some value', ($company->extra_fields)['arbitrary_key'] ?? null);
    }

    /**
     * When a def exists, a valid extra_field value is accepted on create.
     */
    public function test_create_accepts_valid_extra_field_when_def_exists(): void
    {
        $this->makeTextDef('company_segment');

        $response = $this->postJson('/api/companies', [
            'name' => 'Beta Corp',
            'extra_fields' => ['company_segment' => 'Enterprise'],
        ]);

        $response->assertCreated();

        $company = Company::where('name', 'Beta Corp')->first();
        $this->assertSame('Enterprise', ($company->extra_fields)['company_segment'] ?? null);
    }

    /**
     * When defs exist, an unknown key in extra_fields is rejected (422).
     */
    public function test_create_rejects_unknown_extra_field_key_when_defs_exist(): void
    {
        $this->makeTextDef('known_field');

        $this->postJson('/api/companies', [
            'name' => 'Gamma Ltd',
            'extra_fields' => ['unknown_field' => 'value'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('crm_companies', ['name' => 'Gamma Ltd']);
    }

    /**
     * Number field is coerced from string to float on create.
     */
    public function test_create_coerces_number_extra_field(): void
    {
        $this->makeNumberDef('revenue_m');

        $response = $this->postJson('/api/companies', [
            'name' => 'Delta SA',
            'extra_fields' => ['revenue_m' => '99.5'],
        ]);

        $response->assertCreated();

        $company = Company::where('name', 'Delta SA')->first();
        $this->assertSame(99.5, ($company->extra_fields)['revenue_m'] ?? null);
    }

    // ── update path ──────────────────────────────────────────────────────────

    /**
     * Update with valid extra_field passes when def exists.
     */
    public function test_update_accepts_valid_extra_field_when_def_exists(): void
    {
        $this->makeTextDef('crm_notes');
        $company = $this->makeCompany();

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['crm_notes' => 'Important client'],
        ])->assertOk();

        $company->refresh();
        $this->assertSame('Important client', ($company->extra_fields)['crm_notes'] ?? null);
    }

    /**
     * Update with unknown key is rejected (422) when defs are defined.
     */
    public function test_update_rejects_unknown_extra_field_key_when_defs_exist(): void
    {
        $this->makeTextDef('crm_notes');
        $company = $this->makeCompany();

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['rogue_field' => 'injected'],
        ])->assertStatus(422);
    }

    /**
     * Explicitly setting extra_fields to empty array on update clears all values.
     */
    public function test_update_clears_extra_fields_when_set_to_empty_array(): void
    {
        // No defs — free-form.
        $company = $this->makeCompany(['extra_fields' => ['some_key' => 'some_val']]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => [],
        ])->assertOk();

        $company->refresh();
        $this->assertEmpty($company->extra_fields);
    }

    /**
     * Omitting extra_fields from an update payload leaves existing values intact.
     */
    public function test_update_without_extra_fields_key_preserves_existing_values(): void
    {
        $company = $this->makeCompany(['extra_fields' => ['keep_me' => 'preserved']]);

        // Patch only the name — extra_fields not in payload.
        $this->patchJson("/api/companies/{$company->id}", [
            'name' => 'Updated Name',
        ])->assertOk();

        $company->refresh();
        $this->assertSame('Updated Name', $company->name);
        $this->assertSame('preserved', ($company->extra_fields)['keep_me'] ?? null);
    }
}
