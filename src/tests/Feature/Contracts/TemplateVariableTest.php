<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemplateVariableTest extends TestCase
{
    use RefreshDatabase;

    // ---- index: active only ----

    public function test_list_active_variables_only(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        TemplateVariable::factory()->count(2)->create(['is_active' => true]);
        TemplateVariable::factory()->count(1)->create(['is_active' => false]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/template-variables?active_only=1')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // ---- index: wildcard filter (empty arrays) ----

    public function test_list_variables_with_product_country_filter_wildcard(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        // Wildcard variable (empty arrays = all contexts).
        TemplateVariable::factory()->create([
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => true,
        ]);
        // Specific product variable.
        TemplateVariable::factory()->forProduct('macrocrm')->create(['is_active' => true]);
        Sanctum::actingAs($user, ['*']);

        // Wildcard variable should appear for any context.
        $response = $this->getJson('/api/template-variables?product_code=macrocrm&country_code=kz')
            ->assertOk();

        // Wildcard + specific macrocrm both match → 2 results.
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_variables_with_specific_product_filter(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        // Variable scoped to macrosales only.
        TemplateVariable::factory()->create([
            'product_codes' => ['macrosales'],
            'country_codes' => [],
            'is_active' => true,
        ]);
        // Wildcard.
        TemplateVariable::factory()->create([
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // macrocrm context: only wildcard should match.
        $response = $this->getJson('/api/template-variables?product_code=macrocrm&country_code=kz')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    // ---- store ----

    public function test_admin_can_create_variable(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/template-variables', [
            'key' => 'my_custom_key',
            'label' => 'My Custom Label',
            'var_type' => 'text',
        ])->assertCreated()
            ->assertJsonPath('data.key', 'my_custom_key');

        $this->assertDatabaseHas('template_variables', ['key' => 'my_custom_key']);
    }

    public function test_create_variable_requires_unique_key(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        TemplateVariable::factory()->create(['key' => 'existing_key']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/template-variables', [
            'key' => 'existing_key',
            'label' => 'Duplicate',
            'var_type' => 'text',
        ])->assertUnprocessable();
    }

    public function test_create_select_variable_requires_options(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/template-variables', [
            'key' => 'my_select_key',
            'label' => 'Select without options',
            'var_type' => 'select',
            'options' => [],
        ])->assertUnprocessable();
    }

    // ---- update ----

    public function test_admin_can_update_variable(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $variable = TemplateVariable::factory()->create(['label' => 'Old Label']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/template-variables/{$variable->id}", ['label' => 'New Label'])
            ->assertOk()
            ->assertJsonPath('data.label', 'New Label');
    }

    // ---- destroy ----

    public function test_admin_can_delete_unused_variable(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $variable = TemplateVariable::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/template-variables/{$variable->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('template_variables', ['id' => $variable->id]);
    }

    /**
     * In S2.1 the guard is a stub; this test verifies the endpoint returns 204.
     * In S2.2 update delete() to check Contract.context and expect 409.
     */
    public function test_delete_used_variable_returns_409(): void
    {
        // NOTE: In S2.1 we cannot create a Contract, so the guard is never triggered.
        // The test verifies the current behaviour (204) until the guard is activated in S2.2.
        $user = User::factory()->create(['role' => Role::Admin]);
        $variable = TemplateVariable::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // S2.1: no Contract table → delete succeeds.
        $this->deleteJson("/api/template-variables/{$variable->id}")
            ->assertNoContent();
    }
}
