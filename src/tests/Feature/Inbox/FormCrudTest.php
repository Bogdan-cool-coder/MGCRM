<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FormCrudTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    public function test_admin_can_create_form_autogenerates_slug(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $slug = $this->postJson('/api/forms', [
            'name' => 'Заявка',
            'fields' => [['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true]],
        ])->assertCreated()->json('data.public_slug');

        $this->assertNotEmpty($slug);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $slug);
    }

    public function test_duplicate_slug_returns_409(): void
    {
        Form::factory()->create(['public_slug' => 'taken-slug']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson('/api/forms', ['name' => 'x', 'public_slug' => 'taken-slug'])
            ->assertStatus(409);
    }

    public function test_manager_cannot_create_form(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/forms', ['name' => 'x'])->assertForbidden();
    }

    public function test_manager_cannot_list_forms(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/forms')->assertForbidden();
    }

    public function test_public_meta_returns_fields_for_active(): void
    {
        $form = Form::factory()->create([
            'public_slug' => 'meta-slug',
            'fields' => [['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true]],
            'channel_id' => null,
        ]);

        // No auth header — public endpoint.
        $this->getJson('/api/forms/public/meta-slug')
            ->assertOk()
            ->assertJsonPath('data.name', $form->name)
            ->assertJsonPath('data.fields.0.name', 'name')
            // anon view leaks neither slug nor channel.
            ->assertJsonMissingPath('data.public_slug')
            ->assertJsonMissingPath('data.channel_id');
    }

    public function test_public_meta_404_for_inactive(): void
    {
        Form::factory()->inactive()->create(['public_slug' => 'inactive-slug']);

        $this->getJson('/api/forms/public/inactive-slug')->assertNotFound();
    }

    public function test_update_form_slug_conflict_409(): void
    {
        Form::factory()->create(['public_slug' => 'one']);
        $other = Form::factory()->create(['public_slug' => 'two']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->patchJson("/api/forms/{$other->id}", ['public_slug' => 'one'])
            ->assertStatus(409);
    }
}
