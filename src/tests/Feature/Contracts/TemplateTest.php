<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_list_templates_returns_all(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Template::factory()->count(3)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_list_templates_filter_by_kind(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Template::factory()->create(['kind' => 'docx']);
        Template::factory()->create(['kind' => 'yaml']);
        Template::factory()->create(['kind' => 'yaml']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates?kind=yaml')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_templates_filter_by_category(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Template::factory()->create(['category' => 'sublicense_main']);
        Template::factory()->create(['category' => 'addendum']);
        Template::factory()->create(['category' => null]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates?category=sublicense_main')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    // ---- show ----

    public function test_show_template_with_current_version(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $template = Template::factory()->docx()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id]);
        $template->update(['current_version_id' => $version->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/templates/{$template->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'code', 'kind', 'current_version']]);

        $this->assertEquals($version->id, $response->json('data.current_version.id'));
    }

    // ---- update ----

    public function test_lawyer_can_update_template_content(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer]);
        $template = Template::factory()->create(['kind' => 'yaml', 'content' => 'old: true']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/templates/{$template->id}", ['content' => 'new: true'])
            ->assertOk()
            ->assertJsonPath('data.content', 'new: true');
    }

    public function test_manager_cannot_update_template(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $template = Template::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/templates/{$template->id}", ['title' => 'Hack'])
            ->assertForbidden();
    }

    public function test_update_template_increments_version(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $template = Template::factory()->create(['version' => 1]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/templates/{$template->id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);
    }
}
