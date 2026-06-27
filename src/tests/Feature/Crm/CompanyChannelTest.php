<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\ChannelType;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyChannel;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyChannelTest extends TestCase
{
    use RefreshDatabase;

    // ---- list ----

    public function test_can_list_channels_for_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001234567',
            'label' => 'Main',
            'is_primary_for_channel' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}/channels");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel_type', 'phone')
            ->assertJsonPath('data.0.value', '+77001234567');
    }

    // ---- create ----

    public function test_can_create_channel_for_company(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'email',
            'value' => 'info@company.com',
            'label' => 'Office',
            'is_primary_for_channel' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.channel_type', 'email')
            ->assertJsonPath('data.value', 'info@company.com')
            ->assertJsonPath('data.label', 'Office')
            ->assertJsonPath('data.is_primary_for_channel', true);

        $this->assertDatabaseHas('company_channels', [
            'company_id' => $company->id,
            'channel_type' => 'email',
            'value' => 'info@company.com',
        ]);
    }

    // ---- website type ----

    public function test_can_create_website_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'website',
            'value' => 'https://company.com',
            'label' => 'Corporate',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.channel_type', 'website')
            ->assertJsonPath('data.value', 'https://company.com');

        $this->assertDatabaseHas('company_channels', [
            'company_id' => $company->id,
            'channel_type' => 'website',
        ]);
    }

    // ---- multi-value same type ----

    public function test_can_have_multiple_channels_of_same_type(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77001111111',
            'is_primary_for_channel' => true,
        ])->assertCreated();

        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77002222222',
            'is_primary_for_channel' => false,
        ])->assertCreated();

        $this->assertDatabaseCount('company_channels', 2);
    }

    // ---- create-duplicate-422 ----

    public function test_create_duplicate_channel_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001234567',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77001234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('value');
    }

    // ---- invalid channel type ----

    public function test_invalid_channel_type_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'fax',
            'value' => '123456',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('channel_type');
    }

    // ---- update ----

    public function test_can_update_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $channel = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Website->value,
            'value' => 'https://old.com',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/companies/{$company->id}/channels/{$channel->id}", [
            'value' => 'https://new.com',
            'label' => 'Updated site',
        ])->assertOk()
            ->assertJsonPath('data.value', 'https://new.com')
            ->assertJsonPath('data.label', 'Updated site');

        $this->assertDatabaseHas('company_channels', [
            'id' => $channel->id,
            'value' => 'https://new.com',
        ]);
    }

    // ---- delete ----

    public function test_can_delete_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        $channel = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'delete@company.com',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/companies/{$company->id}/channels/{$channel->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Channel deleted.');

        $this->assertDatabaseMissing('company_channels', ['id' => $channel->id]);
    }

    // ---- CompanyResource includes channels on show() ----

    public function test_company_show_includes_channels(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77009990000',
            'label' => 'HQ',
            'is_primary_for_channel' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.channels')
            ->assertJsonPath('data.channels.0.channel_type', 'phone')
            ->assertJsonPath('data.channels.0.value', '+77009990000')
            ->assertJsonPath('data.channels.0.is_primary_for_channel', true);
    }

    // ---- is_primary field returned ----

    public function test_is_primary_for_channel_returned_correctly(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);
        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'primary@company.com',
            'is_primary_for_channel' => true,
        ]);
        CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'secondary@company.com',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}/channels");

        $response->assertOk()->assertJsonCount(2, 'data');

        $primaries = collect($response->json('data'))->where('is_primary_for_channel', true);
        $this->assertCount(1, $primaries);
        $this->assertEquals('primary@company.com', $primaries->first()['value']);
    }

    // ---- authorization ----

    public function test_foreign_manager_cannot_view_channels(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/companies/{$company->id}/channels")
            ->assertForbidden();
    }

    public function test_foreign_manager_cannot_add_channel(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'email',
            'value' => 'hack@company.com',
        ])->assertForbidden();
    }

    public function test_admin_can_manage_any_company_channel(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77000000001',
        ]);

        $response->assertCreated();
    }

    // ---- A-Компании: at-most-one primary per channel_type ----

    public function test_creating_second_phone_as_primary_unsets_first(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        // First phone — primary
        $phone1 = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001111111',
            'is_primary_for_channel' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Second phone — also primary → should unset first
        $this->postJson("/api/companies/{$company->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77002222222',
            'is_primary_for_channel' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_primary_for_channel', true);

        // First phone must now be non-primary
        $this->assertDatabaseHas('company_channels', [
            'id' => $phone1->id,
            'is_primary_for_channel' => false,
        ]);
    }

    public function test_updating_phone_to_primary_unsets_other_phones_but_not_emails(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $phone1 = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001111111',
            'is_primary_for_channel' => true,
        ]);
        $phone2 = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77002222222',
            'is_primary_for_channel' => false,
        ]);
        $email = CompanyChannel::create([
            'company_id' => $company->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'info@company.com',
            'is_primary_for_channel' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Set phone2 as primary
        $this->patchJson("/api/companies/{$company->id}/channels/{$phone2->id}", [
            'is_primary_for_channel' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_primary_for_channel', true);

        // phone1 must now be non-primary
        $this->assertDatabaseHas('company_channels', [
            'id' => $phone1->id,
            'is_primary_for_channel' => false,
        ]);

        // email is a different type — must remain primary
        $this->assertDatabaseHas('company_channels', [
            'id' => $email->id,
            'is_primary_for_channel' => true,
        ]);
    }

    // ---- IDOR: channel must belong to the route-bound company ----

    public function test_update_channel_of_another_company_returns_404(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $companyA = Company::factory()->create(['owner_user_id' => $admin->id]);
        $companyB = Company::factory()->create(['owner_user_id' => $admin->id]);

        // Channel belongs to companyB
        $channel = CompanyChannel::create([
            'company_id' => $companyB->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+70001111111',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($admin, ['*']);

        // Route says companyA, but channel belongs to companyB → 404
        $this->patchJson("/api/companies/{$companyA->id}/channels/{$channel->id}", [
            'value' => '+70009999999',
        ])->assertNotFound();

        // Channel must be unchanged
        $this->assertDatabaseHas('company_channels', [
            'id' => $channel->id,
            'value' => '+70001111111',
        ]);
    }

    public function test_delete_channel_of_another_company_returns_404(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $companyA = Company::factory()->create(['owner_user_id' => $admin->id]);
        $companyB = Company::factory()->create(['owner_user_id' => $admin->id]);

        // Channel belongs to companyB
        $channel = CompanyChannel::create([
            'company_id' => $companyB->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'other@company.com',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($admin, ['*']);

        // Route says companyA, but channel belongs to companyB → 404
        $this->deleteJson("/api/companies/{$companyA->id}/channels/{$channel->id}")
            ->assertNotFound();

        // Channel must still exist
        $this->assertDatabaseHas('company_channels', ['id' => $channel->id]);
    }
}
