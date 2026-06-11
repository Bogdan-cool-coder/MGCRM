<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_can_create_custom_field_def(): void
    {
        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'company',
            'code' => 'crm_segment',
            'label' => 'CRM Сегмент',
            'field_type' => 'select',
            'options' => ['A', 'B', 'C'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'crm_segment')
            ->assertJsonPath('data.entity_scope', 'company');

        $this->assertDatabaseHas('custom_field_defs', [
            'code' => 'crm_segment',
            'entity_scope' => 'company',
        ]);
    }

    public function test_custom_field_code_must_be_slug(): void
    {
        $this->postJson('/api/crm/custom-fields', [
            'entity_scope' => 'contact',
            'code' => 'Invalid Code!',
            'label' => 'Test',
            'field_type' => 'text',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_can_list_custom_fields_by_scope(): void
    {
        CustomFieldDef::create(['entity_scope' => 'company',  'code' => 'cf1', 'label' => 'CF1', 'field_type' => 'text']);
        CustomFieldDef::create(['entity_scope' => 'contact', 'code' => 'cf2', 'label' => 'CF2', 'field_type' => 'text']);

        $this->getJson('/api/crm/custom-fields?scope=company')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_extra_fields_are_stored_in_company_jsonb(): void
    {
        $company = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'extra_fields' => [],
        ]);

        $company->update(['extra_fields' => ['my_field' => 'some value']]);
        $company->refresh();

        $this->assertSame('some value', $company->extra_fields['my_field']);
    }

    public function test_extra_fields_are_stored_in_contact_jsonb(): void
    {
        $contact = Contact::factory()->create([
            'owner_id' => $this->admin->id,
            'extra_fields' => [],
        ]);

        $contact->update(['extra_fields' => ['segment' => 'VIP']]);
        $contact->refresh();

        $this->assertSame('VIP', $contact->extra_fields['segment']);
    }
}
