<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentCrudTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_authenticated_user_can_list_documents(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->count(3)->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_list_documents_filters_by_status(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Document::factory()->submitted()->create(['author_user_id' => $user->id]);
        Document::factory()->submitted()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents?status=submitted')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_documents_hides_archived_by_default(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Document::factory()->archived()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')
            ->assertOk();

        // Only 1 active document; the archived one is hidden.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_list_documents_shows_archived_when_requested(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Document::factory()->archived()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents?archived=1')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // ---- show ----

    public function test_authenticated_user_can_view_own_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $doc->id]);
    }

    public function test_document_policy_manager_cannot_see_others_documents(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/documents/{$doc->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_any_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/documents/{$doc->id}")
            ->assertOk();
    }

    // ---- store ----

    public function test_authenticated_user_can_create_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/documents', [
            'product_code' => 'macrocrm',
            'country_code' => 'kz',
            'title' => 'Test Document',
            'currency' => 'KZT',
        ])->assertCreated();

        $this->assertDatabaseHas('documents', [
            'title' => 'Test Document',
            'product_code' => 'macrocrm',
            'author_user_id' => $user->id,
        ]);
    }

    public function test_create_document_defaults_to_draft_status(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/documents', [
            'product_code' => 'macrosales',
            'country_code' => 'uz',
        ])->assertCreated();

        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_create_document_sets_author_to_current_user(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/documents', [
            'product_code' => 'macrocrm',
            'country_code' => 'kz',
        ])->assertCreated();

        $this->assertSame($user->id, $response->json('data.author_user_id'));
    }

    // ---- update ----

    public function test_author_can_update_own_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/documents/{$doc->id}", [
            'title' => 'Updated Title',
        ])->assertOk()
            ->assertJsonFragment(['title' => 'Updated Title']);
    }

    public function test_cannot_update_document_in_non_draft_status(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->submitted()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/documents/{$doc->id}", [
            'title' => 'Should Fail',
        ])->assertUnprocessable();
    }

    public function test_manager_cannot_update_others_document(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->patchJson("/api/documents/{$doc->id}", ['title' => 'Hacked'])
            ->assertForbidden();
    }

    // ---- destroy ----

    public function test_admin_can_delete_draft_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $admin->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('documents', ['id' => $doc->id]);
    }

    public function test_cannot_delete_non_draft_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->submitted()->create(['author_user_id' => $admin->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}")
            ->assertUnprocessable();
    }

    public function test_manager_cannot_delete_document(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $manager->id]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}")
            ->assertForbidden();
    }

    // ---- BUG-AUTHOR-1 / BUG-COMP-1: list expands author and source_company ----

    public function test_list_documents_includes_author_object(): void
    {
        $user = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Jane Doe']);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('author', $item);
        $this->assertSame($user->id, $item['author']['id']);
        $this->assertSame('Jane Doe', $item['author']['full_name']);
    }

    public function test_list_documents_includes_source_company_object(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['name' => 'ACME Corp']);
        Document::factory()->draft()->create([
            'author_user_id' => $user->id,
            'source_company_id' => $company->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('source_company', $item);
        $this->assertSame($company->id, $item['source_company']['id']);
        $this->assertSame('ACME Corp', $item['source_company']['name']);
    }

    public function test_list_documents_source_company_null_when_not_set(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create([
            'author_user_id' => $user->id,
            'source_company_id' => null,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        $item = $response->json('data.0');
        // source_company should be absent (not loaded) or null — never a raw ID
        $this->assertNull($item['source_company'] ?? null);
    }

    // ---- deal_id filter ----

    public function test_documents_filtered_by_deal_id(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $dealA = Deal::factory()->create();
        $dealB = Deal::factory()->create();
        // Two documents linked to dealA
        Document::factory()->draft()->create(['author_user_id' => $user->id, 'source_deal_id' => $dealA->id]);
        Document::factory()->draft()->create(['author_user_id' => $user->id, 'source_deal_id' => $dealA->id]);
        // One document linked to a different deal
        Document::factory()->draft()->create(['author_user_id' => $user->id, 'source_deal_id' => $dealB->id]);
        // One document with no deal
        Document::factory()->draft()->create(['author_user_id' => $user->id, 'source_deal_id' => null]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/documents?deal_id={$dealA->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertSame($dealA->id, $item['source_deal_id']);
        }
    }

    public function test_deal_id_filter_returns_empty_when_no_match(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = Deal::factory()->create();
        Document::factory()->draft()->create(['author_user_id' => $user->id, 'source_deal_id' => $deal->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents?deal_id=999999')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ---- pagination / filters ----

    public function test_list_documents_paginates(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Document::factory()->count(30)->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents?per_page=10')
            ->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(30, $response->json('meta.total'));
    }
}
