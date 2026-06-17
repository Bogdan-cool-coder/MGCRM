<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // CRUD
    // =========================================================================

    public function test_index_unauthenticated_401(): void
    {
        $this->getJson('/api/message-templates')->assertUnauthorized();
    }

    public function test_index_returns_active_templates_with_bindings(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $t->id,
            'channel_kind' => 'tg',
        ]);
        // Inactive — should not appear
        MessageTemplate::factory()->inactive()->create();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/message-templates')->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertArrayHasKey('bindings', $data[0]);
        $this->assertCount(1, $data[0]['bindings']);
    }

    public function test_store_admin_201(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/message-templates', [
            'title' => 'Тестовый шаблон',
            'body' => 'Привет, {{contact.full_name}}!',
            'subject' => 'Тема {{deal.name}}',
        ])->assertCreated();

        $this->assertSame('Тестовый шаблон', $response->json('data.title'));
        $this->assertSame('Тема {{deal.name}}', $response->json('data.subject'));
        $this->assertDatabaseHas('message_templates', ['title' => 'Тестовый шаблон']);
    }

    public function test_store_manager_403(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/message-templates', [
            'title' => 'X',
            'body' => 'Y',
        ])->assertForbidden();
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/message-templates', [])->assertUnprocessable();
        $this->postJson('/api/message-templates', ['title' => 'X'])->assertUnprocessable();
    }

    public function test_show_returns_template_with_bindings(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $t = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create(['message_template_id' => $t->id, 'channel_kind' => 'wa']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/message-templates/{$t->id}")->assertOk();

        $this->assertSame($t->id, $response->json('data.id'));
        $this->assertCount(1, $response->json('data.bindings'));
    }

    public function test_update_patch_template(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $t = MessageTemplate::factory()->create(['title' => 'Старое название']);

        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->patchJson("/api/message-templates/{$t->id}", [
            'title' => 'Новое название',
            'body' => 'Обновлённое тело {{company.name}}',
        ])->assertOk();

        $this->assertSame('Новое название', $response->json('data.title'));
        $this->assertDatabaseHas('message_templates', ['id' => $t->id, 'title' => 'Новое название']);
    }

    public function test_destroy_soft_deletes(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create(['is_active' => true]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/message-templates/{$t->id}")->assertNoContent();

        $this->assertDatabaseHas('message_templates', ['id' => $t->id, 'is_active' => false]);
    }

    public function test_index_filter_by_is_active(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        MessageTemplate::factory()->create(['is_active' => true]);
        MessageTemplate::factory()->create(['is_active' => false]);

        Sanctum::actingAs($admin, ['*']);

        $responseActive = $this->getJson('/api/message-templates?is_active=1')->assertOk();
        $this->assertCount(1, $responseActive->json('data'));

        $responseInactive = $this->getJson('/api/message-templates?is_active=0')->assertOk();
        $this->assertCount(1, $responseInactive->json('data'));
    }

    public function test_index_filter_by_channel_kind(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $tgTemplate = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create(['message_template_id' => $tgTemplate->id, 'channel_kind' => 'tg']);

        $waTemplate = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create(['message_template_id' => $waTemplate->id, 'channel_kind' => 'wa']);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/message-templates?channel_kind=tg')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($tgTemplate->id, $response->json('data.0.id'));
    }

    // =========================================================================
    // Preview
    // =========================================================================

    public function test_preview_renders_with_provided_vars(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $t = MessageTemplate::factory()->create([
            'subject' => 'Привет, {{contact.full_name}}!',
            'body' => 'Сделка «{{deal.name}}» для {{company.name}}.',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/message-templates/{$t->id}/preview", [
            'vars' => [
                'contact.full_name' => 'Иван Иванов',
                'deal.name' => 'ООО Ромашка',
                'company.name' => 'ООО Ромашка',
            ],
        ])->assertOk();

        $this->assertSame('Привет, Иван Иванов!', $response->json('data.subject'));
        $this->assertSame('Сделка «ООО Ромашка» для ООО Ромашка.', $response->json('data.body'));
        $this->assertEmpty($response->json('data.unresolved_keys'));
    }

    public function test_preview_reports_unresolved_keys(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $t = MessageTemplate::factory()->create([
            'body' => '{{deal.name}} — {{unknown.key}}',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/message-templates/{$t->id}/preview", [
            'vars' => ['deal.name' => 'Тест'],
        ])->assertOk();

        $this->assertContains('unknown.key', $response->json('data.unresolved_keys'));
    }

    public function test_preview_unauthenticated_401(): void
    {
        $t = MessageTemplate::factory()->create();
        $this->postJson("/api/message-templates/{$t->id}/preview", ['vars' => []])
            ->assertUnauthorized();
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    public function test_binding_store_creates_binding(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/message-templates/{$t->id}/bindings", [
            'channel_kind' => 'tg',
        ])->assertCreated();

        $this->assertSame('tg', $response->json('data.channel_kind'));
        $this->assertDatabaseHas('message_template_bindings', [
            'message_template_id' => $t->id,
            'channel_kind' => 'tg',
        ]);
    }

    public function test_binding_store_validates_channel_kind_enum(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create();

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/message-templates/{$t->id}/bindings", [
            'channel_kind' => 'invalid_channel',
        ])->assertUnprocessable();
    }

    public function test_binding_store_validates_activity_type_enum(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create();

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/message-templates/{$t->id}/bindings", [
            'activity_type' => 'bad_type',
        ])->assertUnprocessable();
    }

    public function test_binding_destroy_deletes(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $t = MessageTemplate::factory()->create();
        $binding = MessageTemplateBinding::factory()->create([
            'message_template_id' => $t->id,
            'channel_kind' => 'tg',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/message-templates/{$t->id}/bindings/{$binding->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('message_template_bindings', ['id' => $binding->id]);
    }

    public function test_binding_index_returns_list(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $t = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->count(3)->create(['message_template_id' => $t->id]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/message-templates/{$t->id}/bindings")->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    // =========================================================================
    // Context match
    // =========================================================================

    public function test_context_returns_best_match(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $t = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => $t->id,
            'channel_kind' => 'tg',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/message-templates/context?channel_kind=tg')->assertOk();
        $this->assertSame($t->id, $response->json('data.id'));
    }

    public function test_context_returns_404_when_no_match(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        // No templates with email binding
        MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->create([
            'message_template_id' => MessageTemplate::factory()->create()->id,
            'channel_kind' => 'tg',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/message-templates/context?channel_kind=email')->assertNotFound();
    }

    public function test_context_wildcard_as_fallback(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $t = MessageTemplate::factory()->create();
        // Wildcard binding
        MessageTemplateBinding::factory()->wildcard()->create([
            'message_template_id' => $t->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Any context matches the wildcard
        $this->getJson('/api/message-templates/context?channel_kind=email')->assertOk()
            ->assertJsonPath('data.id', $t->id);
    }
}
