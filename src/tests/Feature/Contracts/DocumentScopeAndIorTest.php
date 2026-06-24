<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for:
 * - documents#3 (IDOR on items.update/destroy, remarks.resolve)
 * - documents#4 (documents.index unscoped)
 */
class DocumentScopeAndIorTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // IDOR: DocumentItem — update/destroy must check document_id
    // ─────────────────────────────────────────────────────────────────────

    public function test_item_update_rejects_item_from_another_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $docA = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $docB = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        // Item belongs to docB.
        $itemB = DocumentItem::factory()->create([
            'document_id' => $docB->id,
            'unit_price' => 10000,
            'qty' => 1.0,
            'line_total' => 10000,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Try to update itemB via docA's URL — should return 404, not 200.
        $this->patchJson("/api/documents/{$docA->id}/items/{$itemB->id}", ['qty' => 5])
            ->assertNotFound();
    }

    public function test_item_destroy_rejects_item_from_another_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $docA = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $docB = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        $itemB = DocumentItem::factory()->create([
            'document_id' => $docB->id,
            'unit_price' => 5000,
            'qty' => 1.0,
            'line_total' => 5000,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Attempting to delete itemB via docA's URL should 404 (not delete from docB).
        $this->deleteJson("/api/documents/{$docA->id}/items/{$itemB->id}")
            ->assertNotFound();

        // Item still exists in DB.
        $this->assertDatabaseHas('document_items', ['id' => $itemB->id]);
    }

    public function test_item_update_succeeds_for_correct_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        $item = DocumentItem::factory()->create([
            'document_id' => $doc->id,
            'unit_price' => 10000,
            'qty' => 1.0,
            'line_total' => 10000,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/documents/{$doc->id}/items/{$item->id}", ['qty' => 3])
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    // IDOR: DocumentRemark.resolve must check document_id
    // ─────────────────────────────────────────────────────────────────────

    public function test_remark_resolve_rejects_remark_from_another_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $author = User::factory()->create(['role' => Role::Manager]);

        $docA = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $docB = Document::factory()->draft()->create(['author_user_id' => $author->id]);

        // Remark belongs to docB.
        $remarkB = DocumentRemark::factory()->create([
            'document_id' => $docB->id,
            'author_user_id' => $admin->id,
            'text' => 'Remark on docB',
        ]);

        // Admin tries to resolve remarkB via docA's URL.
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/documents/{$docA->id}/remarks/{$remarkB->id}/resolve")
            ->assertNotFound();
    }

    public function test_remark_resolve_succeeds_for_correct_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $author = User::factory()->create(['role' => Role::Manager]);

        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'author_user_id' => $admin->id,
            'text' => 'Remark on doc',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    // documents.index scoping — managers see only their own documents
    // ─────────────────────────────────────────────────────────────────────

    public function test_manager_sees_only_own_documents_in_list(): void
    {
        $manager1 = User::factory()->create(['role' => Role::Manager]);
        $manager2 = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $manager1->id]);
        Document::factory()->count(3)->create(['author_user_id' => $manager2->id]);

        Sanctum::actingAs($manager1, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        // manager1 should see only their 2 documents, not manager2's 3.
        $ids = collect($response->json('data'))->pluck('author_user_id')->unique()->values();
        $this->assertCount(2, $response->json('data'));
        $this->assertTrue($ids->every(fn ($id) => $id === $manager1->id));
    }

    public function test_admin_sees_all_documents_in_list(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $admin->id]);
        Document::factory()->count(3)->create(['author_user_id' => $manager->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        // Admin should see all 5 documents.
        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    public function test_lawyer_sees_all_documents_in_list(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $lawyer->id]);
        Document::factory()->count(3)->create(['author_user_id' => $manager->id]);

        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        // Lawyer should see all 5 documents.
        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    public function test_director_sees_all_documents_in_list(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Document::factory()->count(2)->create(['author_user_id' => $director->id]);
        Document::factory()->count(3)->create(['author_user_id' => $manager->id]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        // Director should see all 5 documents.
        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    public function test_manager_can_still_filter_by_source_company_id(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $otherManager = User::factory()->create(['role' => Role::Manager]);

        /** @var \App\Domain\Crm\Models\Company $company */
        $company = \App\Domain\Crm\Models\Company::factory()->create();

        Document::factory()->create(['author_user_id' => $manager->id, 'source_company_id' => $company->id]);
        Document::factory()->create(['author_user_id' => $manager->id, 'source_company_id' => $company->id]);
        // This one belongs to other manager — not visible to manager.
        Document::factory()->create(['author_user_id' => $otherManager->id, 'source_company_id' => $company->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson("/api/documents?source_company_id={$company->id}")->assertOk();

        // Manager sees only their 2 docs for that company (scoped by author).
        $this->assertCount(2, $response->json('data'));
    }
}
