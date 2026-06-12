<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LicensorEntityTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_admin_can_list_licensor_entities(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        LicensorEntity::factory()->count(2)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/licensor-entities')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(2, $response->json('data'));
    }

    // ---- show ----

    public function test_show_licensor_with_bank_accounts(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $entity = LicensorEntity::factory()->create();
        LicensorBankAccount::factory()->create(['licensor_id' => $entity->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/admin/licensor-entities/{$entity->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'country_code', 'accounts']]);

        $this->assertNotEmpty($response->json('data.accounts'));
    }

    // ---- store ----

    public function test_admin_can_create_licensor_entity(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $payload = [
            'country_code' => 'ru',
            'legal_form' => 'ООО',
            'full_legal_form' => 'Общество с ограниченной ответственностью',
            'name' => 'Test Russia Entity',
            'director_position' => 'Директора',
            'director_short' => 'Иванов И.И.',
            'director_genitive' => 'Иванова Ивана Ивановича',
            'tax_id_label' => 'ИНН',
            'tax_id' => '1234567890',
            'address' => 'Москва, ул. Тверская, 1',
            'bank' => 'Сбербанк',
            'bank_code_label' => 'БИК',
            'bank_code' => 'SABRRUMM',
            'account' => '40702810123456789012',
        ];

        $this->postJson('/api/admin/licensor-entities', $payload)
            ->assertCreated()
            ->assertJsonPath('data.country_code', 'ru');

        $this->assertDatabaseHas('licensor_entities', ['country_code' => 'ru']);
    }

    // ---- update ----

    public function test_lawyer_can_update_licensor_entity(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer]);
        $entity = LicensorEntity::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/admin/licensor-entities/{$entity->id}", ['name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    // ---- 403 for manager write ----

    public function test_manager_cannot_create_licensor_entity(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/licensor-entities', ['country_code' => 'de'])
            ->assertForbidden();
    }

    // ---- unique country_code validation ----

    public function test_create_licensor_entity_requires_unique_country_code(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        LicensorEntity::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/licensor-entities', [
            'country_code' => 'kz',
            'legal_form' => 'ТОО',
            'full_legal_form' => 'ТОО test',
            'name' => 'duplicate test',
            'director_position' => 'Директора',
            'director_short' => 'Test',
            'director_genitive' => 'Test',
            'tax_id_label' => 'БИН',
            'tax_id' => '123456789012',
            'address' => 'Astana',
            'bank' => 'TestBank',
            'bank_code_label' => 'БИК',
            'bank_code' => 'TESTBIKK',
            'account' => 'KZ0000000000000',
        ])->assertUnprocessable();
    }

    // ---- bank accounts ----

    public function test_admin_can_add_bank_account(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $entity = LicensorEntity::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/licensor-entities/{$entity->id}/bank-accounts", [
            'currency' => 'USD',
            'bank' => 'Chase',
            'bank_code_label' => 'SWIFT',
            'bank_code' => 'CHASUS33',
            'account' => '1234567890',
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'USD');

        $this->assertDatabaseHas('licensor_bank_accounts', [
            'licensor_id' => $entity->id,
            'currency' => 'USD',
        ]);
    }

    public function test_admin_can_delete_bank_account(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $entity = LicensorEntity::factory()->create();
        $account = LicensorBankAccount::factory()->create(['licensor_id' => $entity->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/admin/bank-accounts/{$account->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('licensor_bank_accounts', ['id' => $account->id]);
    }

    public function test_bank_account_currency_uniqueness_for_primary(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $entity = LicensorEntity::factory()->create();
        // Create a primary USD account via service.
        LicensorBankAccount::factory()->create([
            'licensor_id' => $entity->id,
            'currency' => 'USD',
            'is_primary' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Adding a second primary USD account — service should replace, not throw.
        // The store endpoint calls LicensorService::createAccount() which resets old primary.
        $response = $this->postJson("/api/admin/licensor-entities/{$entity->id}/bank-accounts", [
            'currency' => 'USD',
            'bank' => 'NewBank',
            'bank_code_label' => 'SWIFT',
            'bank_code' => 'NEWBANKX',
            'account' => '9999999999',
            'is_primary' => true,
        ])->assertCreated();

        // Old primary should now be false.
        $this->assertEquals(false, LicensorBankAccount::find(1)?->is_primary ?? true);
    }
}
