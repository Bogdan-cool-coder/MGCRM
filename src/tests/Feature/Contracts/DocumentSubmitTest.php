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

class DocumentSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_submit_draft_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();

        $this->assertSame('submitted', $response->json('data.status'));
    }

    public function test_submit_creates_revision_snapshot(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->withContext([
            'sublicensee' => ['name' => 'ACME Corp'],
            'license' => [],
            'contract' => [],
            'payments' => [],
            'acts' => [],
            'custom' => ['note' => 'Test note'],
        ])->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();

        $revision = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->first();

        $this->assertNotNull($revision);
        $this->assertSame(1, $revision->version_number);
        $this->assertSame(1, $revision->attempt);
        $this->assertSame('ACME Corp', $revision->context_snapshot['sublicensee']['name'] ?? null);
    }

    public function test_submit_with_note_stores_note_in_revision(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit", [
            'note' => 'Please review ASAP',
        ])->assertOk();

        $revision = DocumentRevision::query()->where('document_id', $doc->id)->first();
        $this->assertSame('Please review ASAP', $revision->note);
    }

    public function test_submit_increments_attempt_number(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        // First submit: Draft → Submitted
        $doc = Document::factory()->draft()->create(['author_user_id' => $user->id]);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Manually return to Draft state (NeedsRework → Submitted in real life, but
        // we force it here to test the attempt increment mechanism)
        $doc->update(['status' => ContractStatus::NeedsRework->value]);

        // Second submit: NeedsRework → Submitted
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $revisions = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->orderBy('version_number')
            ->get();

        $this->assertCount(2, $revisions);
        $this->assertSame(1, $revisions[0]->attempt);
        $this->assertSame(2, $revisions[1]->attempt);
        $this->assertSame(1, $revisions[0]->version_number);
        $this->assertSame(2, $revisions[1]->version_number);
    }

    public function test_cannot_submit_non_draft_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->submitted()->create(['author_user_id' => $user->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertUnprocessable();
    }

    public function test_non_author_cannot_submit(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertForbidden();
    }

    public function test_admin_can_submit_any_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();
    }

    public function test_lawyer_can_submit_any_document(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        Sanctum::actingAs($lawyer, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();
    }

    public function test_submit_drive_upload_returns_409_stub(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $admin->id]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/upload-drive")
            ->assertStatus(409);

        $this->assertSame('not_yet_implemented', $response->json('message'));
    }
}
