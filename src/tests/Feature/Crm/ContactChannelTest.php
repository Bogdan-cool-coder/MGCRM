<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\ChannelType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactChannelTest extends TestCase
{
    use RefreshDatabase;

    // ---- list ----

    public function test_can_list_channels_for_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        ContactChannel::create([
            'contact_id' => $contact->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001234567',
            'label' => 'Work',
            'is_primary_for_channel' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/contacts/{$contact->id}/channels");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel_type', 'phone')
            ->assertJsonPath('data.0.value', '+77001234567');
    }

    // ---- create ----

    public function test_can_create_channel_for_contact(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/contacts/{$contact->id}/channels", [
            'channel_type' => 'email',
            'value' => 'test@example.com',
            'label' => 'Personal',
            'is_primary_for_channel' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.channel_type', 'email')
            ->assertJsonPath('data.value', 'test@example.com')
            ->assertJsonPath('data.label', 'Personal')
            ->assertJsonPath('data.is_primary_for_channel', true);

        $this->assertDatabaseHas('contact_channels', [
            'contact_id' => $contact->id,
            'channel_type' => 'email',
            'value' => 'test@example.com',
        ]);
    }

    // ---- create-duplicate-422 ----

    public function test_create_duplicate_channel_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        ContactChannel::create([
            'contact_id' => $contact->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77001234567',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/contacts/{$contact->id}/channels", [
            'channel_type' => 'phone',
            'value' => '+77001234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('value');
    }

    // ---- update ----

    public function test_can_update_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $channel = ContactChannel::create([
            'contact_id' => $contact->id,
            'channel_type' => ChannelType::Telegram->value,
            'value' => '@oldusername',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}/channels/{$channel->id}", [
            'value' => '@newusername',
            'label' => 'Personal TG',
        ])->assertOk()
            ->assertJsonPath('data.value', '@newusername')
            ->assertJsonPath('data.label', 'Personal TG');

        $this->assertDatabaseHas('contact_channels', [
            'id' => $channel->id,
            'value' => '@newusername',
        ]);
    }

    // ---- delete ----

    public function test_can_delete_channel(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $channel = ContactChannel::create([
            'contact_id' => $contact->id,
            'channel_type' => ChannelType::WhatsApp->value,
            'value' => '+77009998877',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/contacts/{$contact->id}/channels/{$channel->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Channel deleted.');

        $this->assertDatabaseMissing('contact_channels', ['id' => $channel->id]);
    }

    // ---- authorization ----

    public function test_foreign_manager_cannot_view_channels(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/contacts/{$contact->id}/channels")
            ->assertForbidden();
    }

    public function test_foreign_manager_cannot_add_channel(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/contacts/{$contact->id}/channels", [
            'channel_type' => 'email',
            'value' => 'hack@example.com',
        ])->assertForbidden();
    }

    // ---- IDOR guard: channel of another contact ----

    public function test_cannot_update_channel_belonging_to_different_contact(): void
    {
        // Attacker owns contactA; victim owns contactB with a channel.
        $attacker = User::factory()->create(['role' => Role::Manager]);
        $victim = User::factory()->create(['role' => Role::Manager]);
        $contactA = Contact::factory()->create(['owner_id' => $attacker->id]);
        $contactB = Contact::factory()->create(['owner_id' => $victim->id]);
        $channelOfB = ContactChannel::create([
            'contact_id' => $contactB->id,
            'channel_type' => ChannelType::Phone->value,
            'value' => '+77000000001',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($attacker, ['*']);

        // Passing contactA's ID in the route but channelOfB's ID — must be 404.
        $this->patchJson("/api/contacts/{$contactA->id}/channels/{$channelOfB->id}", [
            'value' => '+77999999999',
        ])->assertNotFound();
    }

    public function test_cannot_delete_channel_belonging_to_different_contact(): void
    {
        $attacker = User::factory()->create(['role' => Role::Manager]);
        $victim = User::factory()->create(['role' => Role::Manager]);
        $contactA = Contact::factory()->create(['owner_id' => $attacker->id]);
        $contactB = Contact::factory()->create(['owner_id' => $victim->id]);
        $channelOfB = ContactChannel::create([
            'contact_id' => $contactB->id,
            'channel_type' => ChannelType::Email->value,
            'value' => 'victim@example.com',
            'is_primary_for_channel' => false,
        ]);
        Sanctum::actingAs($attacker, ['*']);

        // Must return 404 — not delete channel belonging to another contact.
        $this->deleteJson("/api/contacts/{$contactA->id}/channels/{$channelOfB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('contact_channels', ['id' => $channelOfB->id]);
    }
}
