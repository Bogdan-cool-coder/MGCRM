<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DocumentSubmitTest — S2.2 submit tests updated for S2.6.
 *
 * After S2.6, POST /submit goes through ApprovalService::submit which:
 *   1. Requires docx_path to be set.
 *   2. Requires an active ApprovalRoute.
 *   3. Transitions Draft → Submitted → InReview (not just → Submitted).
 *
 * Tests that assert on the resulting status now expect 'in_review'.
 * Tests that check the revision snapshot / attempt increment still work.
 */
class DocumentSubmitTest extends TestCase
{
    use RefreshDatabase;

    // ---- Helper: create approver + route ----

    private function makeApprover(): User
    {
        return User::factory()->create(['role' => Role::Lawyer]);
    }

    private function makeRoute(User $approver): ApprovalRoute
    {
        return ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [
                ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
            ],
        ]);
    }

    private function docWithDocx(int $authorId): Document
    {
        return Document::factory()->draft()->create([
            'author_user_id' => $authorId,
            'docx_path' => 'documents/test.docx',
        ]);
    }

    // ---- Tests ----

    public function test_author_can_submit_draft_document(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $approver = $this->makeApprover();
        $this->makeRoute($approver);
        $doc = $this->docWithDocx($user->id);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();

        // S2.6: submit now goes Draft → Submitted → InReview in one call
        $this->assertSame('in_review', $response->json('data.status'));
    }

    public function test_submit_creates_revision_snapshot(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $approver = $this->makeApprover();
        $this->makeRoute($approver);
        $doc = Document::factory()->draft()->withContext([
            'sublicensee' => ['name' => 'ACME Corp'],
            'license' => [],
            'contract' => [],
            'payments' => [],
            'acts' => [],
            'custom' => ['note' => 'Test note'],
        ])->create(['author_user_id' => $user->id, 'docx_path' => 'documents/test.docx']);
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
        $approver = $this->makeApprover();
        $this->makeRoute($approver);
        $doc = $this->docWithDocx($user->id);
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
        $approver = $this->makeApprover();
        $this->makeRoute($approver);
        Sanctum::actingAs($user, ['*']);

        // First submit: Draft → Submitted → InReview
        $doc = $this->docWithDocx($user->id);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Force back to NeedsRework (simulating the full cycle)
        $doc->update(['status' => ContractStatus::NeedsRework->value]);

        // Second submit: NeedsRework → Submitted → InReview
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
        $approver = $this->makeApprover();
        $this->makeRoute($approver);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $owner->id,
            'docx_path' => 'documents/test.docx',
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertOk();
    }

    public function test_lawyer_can_submit_any_document(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        // Lawyer is the submitter but must not be in stage-1 user_ids (self-approval guard)
        $otherApprover = User::factory()->create(['role' => Role::Director->value]);
        ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [
                ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$otherApprover->id], 'min_required' => 1],
            ],
        ]);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $owner->id,
            'docx_path' => 'documents/test.docx',
        ]);
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
