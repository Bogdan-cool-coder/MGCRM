<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * G10 — Feature tests for the custom-fields delta (G1/G2/G3/G4/G5/G5b/G6/G9/G12).
 *
 * Each test group is annotated with the gap it covers.
 */
class CustomFieldDeltaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $director;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin    = User::factory()->create(['role' => Role::Admin]);
        $this->director = User::factory()->create(['role' => Role::Director]);
        $this->manager  = User::factory()->create(['role' => Role::Manager]);
    }

    // =========================================================================
    // G1 — contract scope
    // =========================================================================

    public function test_g1_contract_scope_is_valid_enum_value(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contract',
            'code'         => 'contract_notes',
            'label'        => 'Примечания договора',
            'field_type'   => 'textarea',
        ])
            ->assertCreated()
            ->assertJsonPath('data.entity_scope', 'contract');

        $this->assertDatabaseHas('custom_field_defs', [
            'entity_scope' => 'contract',
            'code'         => 'contract_notes',
        ]);
    }

    public function test_g1_schema_endpoint_accepts_contract_scope(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'contract',
            'code'         => 'contract_flag',
            'label'        => 'Contract Flag',
            'field_type'   => 'boolean',
            'is_active'    => true,
        ]);

        $this->getJson('/api/crm/custom-fields/schema?entity_scope=contract')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // =========================================================================
    // G2 — unique(entity_scope, code) → 422 on collision
    // =========================================================================

    public function test_g2_duplicate_scope_code_returns_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'existing_field',
            'label'        => 'Existing',
            'field_type'   => 'text',
        ]);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'existing_field',
            'label'        => 'Duplicate',
            'field_type'   => 'text',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_g2_same_code_different_scope_is_allowed(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'shared_code',
            'label'        => 'On Company',
            'field_type'   => 'text',
        ]);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code'         => 'shared_code',
            'label'        => 'On Contact',
            'field_type'   => 'text',
        ])
            ->assertCreated();
    }

    // =========================================================================
    // G3 — code regex must start with a letter
    // =========================================================================

    public function test_g3_code_starting_with_digit_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code'         => '1bad_code',
            'label'        => 'Test',
            'field_type'   => 'text',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_g3_code_starting_with_underscore_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code'         => '_bad_code',
            'label'        => 'Test',
            'field_type'   => 'text',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_g3_valid_code_starting_with_letter_passes(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code'         => 'abc123_field',
            'label'        => 'Test',
            'field_type'   => 'text',
        ])
            ->assertCreated();
    }

    // =========================================================================
    // G4 — options required for select/multiselect
    // =========================================================================

    public function test_g4_select_without_options_returns_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'segment',
            'label'        => 'Segment',
            'field_type'   => 'select',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('options');
    }

    public function test_g4_multiselect_without_options_returns_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'tags_multi',
            'label'        => 'Tags',
            'field_type'   => 'multiselect',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('options');
    }

    public function test_g4_select_with_options_passes(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'tier',
            'label'        => 'Tier',
            'field_type'   => 'select',
            'options'      => ['Bronze', 'Silver', 'Gold'],
        ])
            ->assertCreated();
    }

    public function test_g4_update_field_type_to_select_requires_options(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $def = CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'some_text',
            'label'        => 'Text Field',
            'field_type'   => 'text',
        ]);

        $this->patchJson("/api/crm/custom-fields/{$def->id}", [
            'field_type' => 'select',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('options');
    }

    // =========================================================================
    // G5 — reorder endpoint
    // =========================================================================

    public function test_g5_reorder_updates_sort_order(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $first  = CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'field_a', 'label' => 'A', 'field_type' => 'text', 'sort_order' => 0]);
        $second = CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'field_b', 'label' => 'B', 'field_type' => 'text', 'sort_order' => 1]);

        $this->patchJson('/api/crm/custom-fields/reorder?entity_scope=company', [
            'items' => [
                ['id' => $second->id, 'sort_order' => 0],
                ['id' => $first->id,  'sort_order' => 1],
            ],
        ])
            ->assertOk();

        $this->assertDatabaseHas('custom_field_defs', ['id' => $first->id,  'sort_order' => 1]);
        $this->assertDatabaseHas('custom_field_defs', ['id' => $second->id, 'sort_order' => 0]);
    }

    public function test_g5_reorder_rejects_cross_scope_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $contactDef = CustomFieldDef::create(['entity_scope' => 'contact', 'code' => 'contact_f', 'label' => 'C', 'field_type' => 'text']);

        $this->patchJson('/api/crm/custom-fields/reorder?entity_scope=company', [
            'items' => [
                ['id' => $contactDef->id, 'sort_order' => 0],
            ],
        ])
            ->assertStatus(422);
    }

    public function test_g5_reorder_requires_admin_write(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $def = CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'mfield', 'label' => 'M', 'field_type' => 'text']);

        // FormRequest passes (valid payload); authorization gate should block the manager.
        $this->patchJson('/api/crm/custom-fields/reorder?entity_scope=company', [
            'items' => [['id' => $def->id, 'sort_order' => 0]],
        ])
            ->assertStatus(403);
    }

    public function test_g5_reorder_missing_scope_returns_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson('/api/crm/custom-fields/reorder', [
            'items' => [['id' => 1, 'sort_order' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('entity_scope');
    }

    // =========================================================================
    // G5b — index includes inactive by default
    // =========================================================================

    public function test_g5b_index_includes_inactive_by_default(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'active_f',   'label' => 'Active',   'field_type' => 'text', 'is_active' => true]);
        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'inactive_f', 'label' => 'Inactive', 'field_type' => 'text', 'is_active' => false]);

        $response = $this->getJson('/api/crm/custom-fields?scope=company')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_g5b_index_include_inactive_zero_hides_inactive(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'active_f',   'label' => 'Active',   'field_type' => 'text', 'is_active' => true]);
        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'inactive_f', 'label' => 'Inactive', 'field_type' => 'text', 'is_active' => false]);

        $response = $this->getJson('/api/crm/custom-fields?scope=company&include_inactive=0')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('active_f', $response->json('data.0.code'));
    }

    public function test_g5b_schema_endpoint_remains_active_only(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'active_s',   'label' => 'Active Schema',   'field_type' => 'text', 'is_active' => true]);
        CustomFieldDef::create(['entity_scope' => 'company', 'code' => 'inactive_s', 'label' => 'Inactive Schema', 'field_type' => 'text', 'is_active' => false]);

        $response = $this->getJson('/api/crm/custom-fields/schema?entity_scope=company')
            ->assertOk();

        // schema endpoint must return only active; two groups possible but total fields = 1
        $totalFields = collect($response->json('data'))->sum(fn ($g) => count($g['fields']));
        $this->assertSame(1, $totalFields);
    }

    // =========================================================================
    // G6 — coerce/validate values parity
    // =========================================================================

    public function test_g6_select_rejects_out_of_options_value(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'tier',
            'label'        => 'Tier',
            'field_type'   => 'select',
            'options'      => ['Bronze', 'Silver', 'Gold'],
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['tier' => 'Platinum'],
        ])
            ->assertStatus(422);
    }

    public function test_g6_select_accepts_valid_option_value(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'tier',
            'label'        => 'Tier',
            'field_type'   => 'select',
            'options'      => ['Bronze', 'Silver', 'Gold'],
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['tier' => 'Gold'],
        ])
            ->assertOk();

        $company->refresh();
        $this->assertSame('Gold', $company->extra_fields['tier']);
    }

    public function test_g6_multiselect_stores_as_array(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'labels',
            'label'        => 'Labels',
            'field_type'   => 'multiselect',
            'options'      => ['VIP', 'Partner', 'Lead'],
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['labels' => ['VIP', 'Partner']],
        ])
            ->assertOk();

        $company->refresh();
        $this->assertIsArray($company->extra_fields['labels']);
        $this->assertSame(['VIP', 'Partner'], $company->extra_fields['labels']);
    }

    public function test_g6_multiselect_rejects_out_of_options_value(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'categories',
            'label'        => 'Categories',
            'field_type'   => 'multiselect',
            'options'      => ['A', 'B', 'C'],
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['categories' => ['A', 'Z']],
        ])
            ->assertStatus(422);
    }

    public function test_g6_url_field_rejects_invalid_url(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'website_custom',
            'label'        => 'Website',
            'field_type'   => 'url',
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['website_custom' => 'not a url'],
        ])
            ->assertStatus(422);
    }

    public function test_g6_url_field_accepts_valid_url(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'website_custom',
            'label'        => 'Website',
            'field_type'   => 'url',
            'is_active'    => true,
        ]);

        $company = \App\Domain\Crm\Models\Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $this->patchJson("/api/companies/{$company->id}", [
            'extra_fields' => ['website_custom' => 'https://example.com'],
        ])
            ->assertOk();
    }

    // =========================================================================
    // G9 — status codes (201 create / 204 delete)
    // =========================================================================

    public function test_g9_store_returns_201(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code'         => 'contact_status',
            'label'        => 'Contact Status',
            'field_type'   => 'text',
        ])
            ->assertStatus(201);
    }

    public function test_g9_destroy_returns_204(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $def = CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code'         => 'to_delete',
            'label'        => 'Delete Me',
            'field_type'   => 'text',
        ]);

        $this->deleteJson("/api/crm/custom-fields/{$def->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('custom_field_defs', ['id' => $def->id]);
    }

    // =========================================================================
    // G12 — director has admin-write (already in seeder — verify it resolves)
    // =========================================================================

    public function test_g12_director_can_create_custom_field(): void
    {
        Sanctum::actingAs($this->director, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'director_field',
            'label'        => 'Director Field',
            'field_type'   => 'text',
        ])
            ->assertCreated();
    }

    public function test_g12_director_can_delete_custom_field(): void
    {
        Sanctum::actingAs($this->director, ['*']);

        $def = CustomFieldDef::create([
            'entity_scope' => 'company',
            'code'         => 'dir_delete',
            'label'        => 'Dir Delete',
            'field_type'   => 'text',
        ]);

        $this->deleteJson("/api/crm/custom-fields/{$def->id}")
            ->assertNoContent();
    }

    public function test_g12_manager_cannot_create_custom_field(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code'         => 'manager_field',
            'label'        => 'Manager Field',
            'field_type'   => 'text',
        ])
            ->assertStatus(403);
    }
}
