<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_revisions_returns_correct_count(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        DocumentRevision::factory()->create(['document_id' => $doc->id, 'version_number' => 1]);
        DocumentRevision::factory()->create(['document_id' => $doc->id, 'version_number' => 2]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/documents/{$doc->id}/revisions")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_revision_context_snapshot_matches_document_context_at_submit_time(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $context = [
            'sublicensee' => ['name' => 'ACME Corp', 'bin' => '999888777'],
            'license' => ['product' => 'MacroCRM'],
            'contract' => [],
            'payments' => [],
            'acts' => [],
            'custom' => ['note' => 'Snapshot test'],
        ];
        $doc = Document::factory()->draft()->withContext($context)->create([
            'author_user_id' => $user->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Submit to trigger revision snapshot.
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $revision = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->first();

        $this->assertNotNull($revision);
        $this->assertSame('ACME Corp', $revision->context_snapshot['sublicensee']['name'] ?? null);
        $this->assertSame('Snapshot test', $revision->context_snapshot['custom']['note'] ?? null);
    }

    public function test_resubmit_after_rework_creates_new_revision_with_incremented_version_number(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);

        // First submit.
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Simulate needs_rework to allow resubmit.
        $doc->update(['status' => ContractStatus::NeedsRework->value]);

        // Second submit.
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $count = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->count();
        $this->assertSame(2, $count);

        $latest = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->orderByDesc('version_number')
            ->first();
        $this->assertSame(2, $latest->version_number);
        $this->assertSame(2, $latest->attempt);
    }

    public function test_can_fetch_single_revision(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $revision = DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 1,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/documents/{$doc->id}/revisions/{$revision->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $revision->id, 'version_number' => 1]);
    }

    public function test_manager_cannot_list_revisions_of_others_documents(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/documents/{$doc->id}/revisions")
            ->assertForbidden();
    }
}
