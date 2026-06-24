<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\AcquisitionChannel;
use App\Domain\Crm\Models\AcquisitionChannelHistory;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\AcquisitionChannelHistoryService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\AcquisitionChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for:
 *   - AcquisitionChannel admin directory CRUD
 *   - acquisition_channel_id field on Company and Contact
 *   - AcquisitionChannelHistory: written on change, not written if unchanged
 *   - AcquisitionChannelSeeder idempotence
 */
class AcquisitionChannelTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Directory CRUD (admin)
    // =========================================================================

    public function test_manager_cannot_list_acquisition_channels(): void
    {
        // NEW-5: acquisition channels are sensitive BI — the /api/admin/* group
        // is admin/director only, so a manager must get 403 on read.
        AcquisitionChannel::create(['name' => 'Рекомендации', 'sort_order' => 1]);
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/acquisition-channels')->assertForbidden();
    }

    public function test_admin_can_list_acquisition_channels(): void
    {
        AcquisitionChannel::create(['name' => 'Рекомендации', 'sort_order' => 1]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/admin/acquisition-channels')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_manager_cannot_create_acquisition_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/acquisition-channels', ['name' => 'Рекомендации'])
            ->assertForbidden();
    }

    public function test_admin_can_create_acquisition_channel(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/acquisition-channels', [
            'name' => 'Рекомендации клиентов',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Рекомендации клиентов');

        $this->assertDatabaseHas('acquisition_channels', ['name' => 'Рекомендации клиентов']);
    }

    public function test_director_can_update_acquisition_channel(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Холодный звонок', 'sort_order' => 2]);
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->putJson("/api/admin/acquisition-channels/{$channel->id}", [
            'name' => 'Холодный звонок (обновлено)',
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Холодный звонок (обновлено)');
    }

    public function test_admin_can_delete_acquisition_channel(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Удаляемый', 'sort_order' => 99]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/acquisition-channels/{$channel->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('acquisition_channels', ['id' => $channel->id]);
    }

    public function test_manager_cannot_delete_acquisition_channel(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Нельзя удалить', 'sort_order' => 99]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/acquisition-channels/{$channel->id}")
            ->assertForbidden();
    }

    public function test_active_only_filter(): void
    {
        AcquisitionChannel::create(['name' => 'Активный', 'sort_order' => 1, 'is_active' => true]);
        AcquisitionChannel::create(['name' => 'Неактивный', 'sort_order' => 2, 'is_active' => false]);

        // Reads on /api/admin/* require admin/director (NEW-5).
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/acquisition-channels?active_only=1')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Активный', $names);
        $this->assertNotContains('Неактивный', $names);
    }

    // =========================================================================
    // acquisition_channel_id on Company
    // =========================================================================

    public function test_company_acquisition_channel_saved_on_create(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Рекомендации', 'sort_order' => 1]);
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', [
            'name' => 'Test Company',
            'acquisition_channel_id' => $channel->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.acquisition_channel_id', $channel->id);

        $this->assertDatabaseHas('crm_companies', [
            'name' => 'Test Company',
            'acquisition_channel_id' => $channel->id,
        ]);
    }

    public function test_company_acquisition_channel_id_nullable(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/companies', ['name' => 'No Channel']);

        $response->assertCreated()
            ->assertJsonPath('data.acquisition_channel_id', null);
    }

    public function test_invalid_acquisition_channel_id_fails_validation_on_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/companies', [
            'name' => 'Test',
            'acquisition_channel_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('acquisition_channel_id');
    }

    // =========================================================================
    // acquisition_channel_id on Contact
    // =========================================================================

    public function test_contact_acquisition_channel_saved_on_create(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Выставка', 'sort_order' => 3]);
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/contacts', [
            'full_name' => 'Иван Петров',
            'acquisition_channel_id' => $channel->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.acquisition_channel_id', $channel->id);

        $this->assertDatabaseHas('crm_contacts', [
            'full_name' => 'Иван Петров',
            'acquisition_channel_id' => $channel->id,
        ]);
    }

    public function test_invalid_acquisition_channel_id_fails_validation_on_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/contacts', [
            'full_name' => 'Test',
            'acquisition_channel_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('acquisition_channel_id');
    }

    // =========================================================================
    // Acquisition channel history — Company
    // =========================================================================

    public function test_history_written_when_company_channel_changes(): void
    {
        $channelA = AcquisitionChannel::create(['name' => 'Канал A', 'sort_order' => 1]);
        $channelB = AcquisitionChannel::create(['name' => 'Канал B', 'sort_order' => 2]);

        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'acquisition_channel_id' => $channelA->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/companies/{$company->id}", [
            'acquisition_channel_id' => $channelB->id,
        ])->assertOk();

        $this->assertDatabaseHas('acquisition_channel_history', [
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'old_channel_id' => $channelA->id,
            'new_channel_id' => $channelB->id,
        ]);
    }

    public function test_history_not_written_when_company_channel_unchanged(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Канал X', 'sort_order' => 1]);

        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'acquisition_channel_id' => $channel->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Update a different field — channel not included in payload
        $this->patchJson("/api/companies/{$company->id}", [
            'name' => 'New Name',
        ])->assertOk();

        $this->assertDatabaseMissing('acquisition_channel_history', [
            'entity_type' => 'company',
            'entity_id' => $company->id,
        ]);
    }

    public function test_history_written_when_company_channel_set_from_null(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Первый канал', 'sort_order' => 1]);

        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'acquisition_channel_id' => null,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/companies/{$company->id}", [
            'acquisition_channel_id' => $channel->id,
        ])->assertOk();

        $this->assertDatabaseHas('acquisition_channel_history', [
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'old_channel_id' => null,
            'new_channel_id' => $channel->id,
        ]);
    }

    // =========================================================================
    // Acquisition channel history — Contact
    // =========================================================================

    public function test_history_written_when_contact_channel_changes(): void
    {
        $channelA = AcquisitionChannel::create(['name' => 'Канал C', 'sort_order' => 1]);
        $channelB = AcquisitionChannel::create(['name' => 'Канал D', 'sort_order' => 2]);

        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create([
            'owner_id' => $user->id,
            'acquisition_channel_id' => $channelA->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", [
            'acquisition_channel_id' => $channelB->id,
        ])->assertOk();

        $this->assertDatabaseHas('acquisition_channel_history', [
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
            'old_channel_id' => $channelA->id,
            'new_channel_id' => $channelB->id,
        ]);
    }

    public function test_history_not_written_when_contact_channel_not_in_payload(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", [
            'full_name' => 'Updated Name',
        ])->assertOk();

        $this->assertDatabaseMissing('acquisition_channel_history', [
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
        ]);
    }

    // =========================================================================
    // History read endpoint
    // =========================================================================

    public function test_company_channel_history_endpoint_returns_records(): void
    {
        $channelA = AcquisitionChannel::create(['name' => 'Первый', 'sort_order' => 1]);
        $channelB = AcquisitionChannel::create(['name' => 'Второй', 'sort_order' => 2]);
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        AcquisitionChannelHistory::create([
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'old_channel_id' => $channelA->id,
            'new_channel_id' => $channelB->id,
            'changed_by' => $user->id,
            'changed_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/companies/{$company->id}/channel-history")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_contact_channel_history_endpoint_returns_records(): void
    {
        $channel = AcquisitionChannel::create(['name' => 'Канал', 'sort_order' => 1]);
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        AcquisitionChannelHistory::create([
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
            'old_channel_id' => null,
            'new_channel_id' => $channel->id,
            'changed_by' => $user->id,
            'changed_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/contacts/{$contact->id}/channel-history")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // =========================================================================
    // AcquisitionChannelHistoryService — unit-level
    // =========================================================================

    public function test_service_does_not_write_record_when_channel_unchanged(): void
    {
        $service = app(AcquisitionChannelHistoryService::class);
        $channel = AcquisitionChannel::create(['name' => 'Same', 'sort_order' => 1]);

        $service->record('company', 1, $channel->id, $channel->id, null);

        $this->assertDatabaseMissing('acquisition_channel_history', [
            'entity_type' => 'company',
            'entity_id' => 1,
        ]);
    }

    public function test_service_writes_record_when_channel_changes(): void
    {
        $service = app(AcquisitionChannelHistoryService::class);
        $channelA = AcquisitionChannel::create(['name' => 'A', 'sort_order' => 1]);
        $channelB = AcquisitionChannel::create(['name' => 'B', 'sort_order' => 2]);

        $service->record('contact', 99, $channelA->id, $channelB->id, null);

        $this->assertDatabaseHas('acquisition_channel_history', [
            'entity_type' => 'contact',
            'entity_id' => 99,
            'old_channel_id' => $channelA->id,
            'new_channel_id' => $channelB->id,
        ]);
    }

    // =========================================================================
    // Seeder idempotence
    // =========================================================================

    public function test_acquisition_channel_seeder_is_idempotent(): void
    {
        $seeder = new AcquisitionChannelSeeder;

        $seeder->run();
        $countAfterFirst = AcquisitionChannel::count();

        $seeder->run();
        $countAfterSecond = AcquisitionChannel::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }

    public function test_seeder_creates_expected_channels(): void
    {
        (new AcquisitionChannelSeeder)->run();

        $this->assertDatabaseHas('acquisition_channels', ['name' => 'Рекомендации клиентов']);
        $this->assertDatabaseHas('acquisition_channels', ['name' => 'Входящий запрос']);
        $this->assertDatabaseHas('acquisition_channels', ['name' => 'Другое']);
    }
}
