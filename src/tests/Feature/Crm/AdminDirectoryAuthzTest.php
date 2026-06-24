<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Models\ContactPosition;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Models\Source;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Authorization tests for admin-only CRM directory endpoints
 * and CustomFieldDef write endpoints.
 *
 * Rules:
 *  - /api/admin/* directory (company-types / contact-positions / sources / ...):
 *    the WHOLE group (index / show / store / update / destroy) is admin or
 *    director only — 403 for manager (NEW-5: route-level `can:admin-write`).
 *  - /api/crm/custom-fields: writes are admin/director only; index/show stay
 *    open to any authenticated user (separate route group, not under /admin).
 */
class AdminDirectoryAuthzTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // CompanyType
    // =========================================================================

    public function test_manager_cannot_create_company_type(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/company-types', ['name' => 'ТОО'])
            ->assertForbidden();
    }

    public function test_admin_can_create_company_type(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/company-types', ['name' => 'ТОО Тест'])
            ->assertSuccessful();
    }

    public function test_director_can_create_company_type(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/admin/company-types', ['name' => 'АО Тест'])
            ->assertSuccessful();
    }

    public function test_manager_cannot_update_company_type(): void
    {
        $type = CompanyType::create(['name' => 'ИП', 'sort_order' => 1]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->putJson("/api/admin/company-types/{$type->id}", ['name' => 'ИП Updated'])
            ->assertForbidden();
    }

    public function test_admin_can_update_company_type(): void
    {
        $type = CompanyType::create(['name' => 'ООО', 'sort_order' => 1]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->putJson("/api/admin/company-types/{$type->id}", ['name' => 'ООО Updated'])
            ->assertSuccessful();
    }

    public function test_manager_cannot_delete_company_type(): void
    {
        $type = CompanyType::create(['name' => 'ПАО', 'sort_order' => 1]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/company-types/{$type->id}")
            ->assertForbidden();
    }

    public function test_admin_can_delete_company_type(): void
    {
        $type = CompanyType::create(['name' => 'Удаляемый', 'sort_order' => 99]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/company-types/{$type->id}")
            ->assertSuccessful();
    }

    public function test_manager_cannot_list_company_types(): void
    {
        // NEW-5: the /api/admin/* directory group is admin/director only.
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/company-types')->assertForbidden();
    }

    // =========================================================================
    // ContactPosition
    // =========================================================================

    public function test_manager_cannot_create_contact_position(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/contact-positions', ['name' => 'Директор'])
            ->assertForbidden();
    }

    public function test_admin_can_create_contact_position(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/contact-positions', ['name' => 'Директор Тест'])
            ->assertSuccessful();
    }

    public function test_manager_cannot_update_contact_position(): void
    {
        $pos = ContactPosition::create(['name' => 'Менеджер', 'sort_order' => 1]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->putJson("/api/admin/contact-positions/{$pos->id}", ['name' => 'Updated'])
            ->assertForbidden();
    }

    public function test_manager_cannot_delete_contact_position(): void
    {
        $pos = ContactPosition::create(['name' => 'Бухгалтер', 'sort_order' => 1]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/contact-positions/{$pos->id}")
            ->assertForbidden();
    }

    public function test_director_can_update_contact_position(): void
    {
        $pos = ContactPosition::create(['name' => 'Аналитик', 'sort_order' => 1]);
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->putJson("/api/admin/contact-positions/{$pos->id}", ['name' => 'Старший аналитик'])
            ->assertSuccessful();
    }

    public function test_manager_cannot_list_contact_positions(): void
    {
        // NEW-5: the /api/admin/* directory group is admin/director only.
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/contact-positions')->assertForbidden();
    }

    // =========================================================================
    // Source
    // =========================================================================

    public function test_manager_cannot_create_source(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/sources', ['code' => 'web', 'name' => 'Website'])
            ->assertForbidden();
    }

    public function test_admin_can_create_source(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/sources', ['code' => 'web_new', 'name' => 'Website New'])
            ->assertSuccessful();
    }

    public function test_manager_cannot_update_source(): void
    {
        $source = Source::create(['code' => 'test_cold_call', 'name' => 'Test Cold Call', 'sort_order' => 99]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->putJson("/api/admin/sources/{$source->id}", ['code' => 'test_cold_call', 'name' => 'Updated'])
            ->assertForbidden();
    }

    public function test_manager_cannot_delete_source(): void
    {
        $source = Source::create(['code' => 'test_partner', 'name' => 'Test Partner', 'sort_order' => 99]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/sources/{$source->id}")
            ->assertForbidden();
    }

    public function test_manager_cannot_list_sources(): void
    {
        // NEW-5: the /api/admin/* directory group is admin/director only.
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/sources')->assertForbidden();
    }

    // =========================================================================
    // CustomFieldDef
    // =========================================================================

    public function test_manager_cannot_create_custom_field(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code' => 'segment_mgr',
            'label' => 'Segment',
            'field_type' => 'text',
        ])->assertForbidden();
    }

    public function test_admin_can_create_custom_field(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code' => 'segment_adm',
            'label' => 'Segment Admin',
            'field_type' => 'text',
        ])->assertSuccessful();
    }

    public function test_director_can_create_custom_field(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code' => 'segment_dir',
            'label' => 'Segment Dir',
            'field_type' => 'text',
        ])->assertSuccessful();
    }

    public function test_manager_cannot_update_custom_field(): void
    {
        $def = CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'cf_to_update',
            'label' => 'Original',
            'field_type' => 'text',
        ]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->putJson("/api/crm/custom-fields/{$def->id}", [
            'label' => 'Updated Label',
        ])->assertForbidden();
    }

    public function test_admin_can_update_custom_field(): void
    {
        $def = CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'cf_admin_upd',
            'label' => 'Original',
            'field_type' => 'text',
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->putJson("/api/crm/custom-fields/{$def->id}", [
            'label' => 'Updated Label',
        ])->assertSuccessful();
    }

    public function test_manager_cannot_delete_custom_field(): void
    {
        $def = CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => 'cf_del_mgr',
            'label' => 'To Delete',
            'field_type' => 'text',
        ]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/crm/custom-fields/{$def->id}")
            ->assertForbidden();
    }

    public function test_admin_can_delete_custom_field(): void
    {
        $def = CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => 'cf_del_adm',
            'label' => 'To Delete Admin',
            'field_type' => 'text',
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/crm/custom-fields/{$def->id}")
            ->assertSuccessful();
    }

    public function test_manager_can_list_custom_fields(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/crm/custom-fields')->assertOk();
    }
}
