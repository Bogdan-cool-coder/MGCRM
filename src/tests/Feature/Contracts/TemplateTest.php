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

    // BUG-DOC-2: template selection by product/country must use whereJsonContains, not
    // scalar WHERE, because product_codes / country_codes are JSON array columns.

    public function test_list_templates_filter_by_product_code_exact_match(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Template::factory()->create(['product_codes' => ['macrocrm', 'macrosales']]);
        Template::factory()->create(['product_codes' => ['macrosales']]);
        Template::factory()->create(['product_codes' => []]); // wildcard
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates?product_code=macrocrm')
            ->assertOk();

        // Wildcard (empty array) + exact-match → 2 results
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_templates_filter_by_country_code_exact_match(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Template::factory()->create(['country_codes' => ['kz', 'uz']]);
        Template::factory()->create(['country_codes' => ['ru']]);
        Template::factory()->create(['country_codes' => []]); // wildcard
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates?country_code=kz')
            ->assertOk();

        // Wildcard + kz match → 2 results
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_templates_filter_by_product_and_country(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        // Both match
        Template::factory()->create([
            'product_codes' => ['macrocrm'],
            'country_codes' => ['kz'],
        ]);
        // Product matches, country wildcard
        Template::factory()->create([
            'product_codes' => ['macrocrm'],
            'country_codes' => [],
        ]);
        // Neither matches
        Template::factory()->create([
            'product_codes' => ['macrosales'],
            'country_codes' => ['uz'],
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/templates?product_code=macrocrm&country_code=kz')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
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

    // ---- store ----

    public function test_admin_can_create_template(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/templates', [
            'code' => 'my_new_template',
            'kind' => 'docx',
            'title' => 'My New Template',
        ])->assertCreated()
          ->assertJsonStructure(['data' => ['id', 'code', 'kind', 'title', 'version', 'current_version']]);

        $this->assertSame('my_new_template', $response->json('data.code'));
        $this->assertSame('docx', $response->json('data.kind'));
        $this->assertSame(0, $response->json('data.version'));
        $this->assertNull($response->json('data.current_version'));

        $this->assertDatabaseHas('templates', ['code' => 'my_new_template']);
    }

    public function test_created_template_appears_in_list(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/templates', [
            'code' => 'listed_template',
            'kind' => 'yaml',
            'title' => 'Listed Template',
        ])->assertCreated();

        $response = $this->getJson('/api/templates')->assertOk();
        $codes = array_column($response->json('data'), 'code');
        $this->assertContains('listed_template', $codes);
    }

    public function test_create_template_with_scopes(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/templates', [
            'code' => 'scoped_template',
            'kind' => 'docx',
            'title' => 'Scoped Template',
            'category' => 'addendum',
            'product_codes' => ['macrocrm'],
            'country_codes' => ['kz', 'uz'],
        ])->assertCreated();

        $this->assertSame('addendum', $response->json('data.category'));
        $this->assertSame(['macrocrm'], $response->json('data.product_codes'));
        $this->assertSame(['kz', 'uz'], $response->json('data.country_codes'));
    }

    public function test_duplicate_code_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Template::factory()->create(['code' => 'existing_code']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/templates', [
            'code' => 'existing_code',
            'kind' => 'docx',
            'title' => 'Dupe',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    public function test_invalid_kind_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/templates', [
            'code' => 'bad_kind_template',
            'kind' => 'pdf',
            'title' => 'Bad Kind',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['kind']);
    }

    public function test_invalid_code_format_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/templates', [
            'code' => 'Has Spaces',
            'kind' => 'docx',
            'title' => 'Bad Code',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    public function test_manager_cannot_create_template(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/templates', [
            'code' => 'manager_template',
            'kind' => 'docx',
            'title' => 'Manager Template',
        ])->assertForbidden();
    }

    public function test_unauthenticated_cannot_create_template(): void
    {
        $this->postJson('/api/templates', [
            'code' => 'anon_template',
            'kind' => 'docx',
            'title' => 'Anon Template',
        ])->assertUnauthorized();
    }
}
