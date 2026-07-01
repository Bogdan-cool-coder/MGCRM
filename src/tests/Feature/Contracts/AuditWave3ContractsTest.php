<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Policies\ApprovalPolicy;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for audit Wave 3 (minor/trivial) contract-domain fixes.
 *
 * Covers:
 *   - minor BUG: ApprovalRoutesPage template_code — resource exposes template_code
 *   - minor CONVENTION: showApproval uses Policy instead of inline role check
 *   - minor BUG: DocumentAttachmentResource exposes uploaded_by_name and size
 *   - trivial BUG: canUnsign requires admin/lawyer (not author) — verified at policy level
 */
class AuditWave3ContractsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // ApprovalRoute resource exposes template_code
    // =========================================================================

    public function test_approval_route_index_exposes_template_code(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'is_default' => true,
            'stages' => [['order' => 1, 'name' => 'Stage 1', 'user_ids' => [], 'min_required' => 1]],
        ]);

        $response = $this->getJson('/api/approval-routes');

        $response->assertOk();
        // template_code key must be present (null when no template linked)
        $this->assertArrayHasKey('template_code', $response->json('data.0'));
    }

    // =========================================================================
    // showApproval uses ApprovalPolicy (no inline role check)
    // =========================================================================

    public function test_show_approval_allows_assigned_approver(): void
    {
        $approver = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($approver, ['*']);

        $doc = Document::factory()->create();
        $approval = Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $approver->id,
        ]);

        $response = $this->getJson("/api/approvals/{$approval->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $approval->id);
    }

    public function test_show_approval_allows_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $approver = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($admin, ['*']);

        $doc = Document::factory()->create();
        $approval = Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $approver->id,
        ]);

        $this->getJson("/api/approvals/{$approval->id}")->assertOk();
    }

    public function test_show_approval_allows_lawyer(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $approver = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($lawyer, ['*']);

        $doc = Document::factory()->create();
        $approval = Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $approver->id,
        ]);

        $this->getJson("/api/approvals/{$approval->id}")->assertOk();
    }

    public function test_show_approval_denies_non_approver_manager(): void
    {
        $stranger = User::factory()->create(['role' => Role::Manager]);
        $approver = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($stranger, ['*']);

        $doc = Document::factory()->create();
        $approval = Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $approver->id,
        ]);

        $this->getJson("/api/approvals/{$approval->id}")->assertForbidden();
    }

    // =========================================================================
    // ApprovalPolicy unit test
    // =========================================================================

    public function test_approval_policy_view_allows_assigned_user(): void
    {
        // Use create so the user gets a real persisted ID
        $user = User::factory()->create(['role' => Role::Manager]);
        $approval = new Approval(['user_id' => $user->id]);

        $policy = new ApprovalPolicy;
        $this->assertTrue($policy->view($user, $approval));
    }

    public function test_approval_policy_view_denies_other_manager(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        // Different user's approval
        $otherUser = User::factory()->create(['role' => Role::Manager]);
        $approval = new Approval(['user_id' => $otherUser->id]);

        $policy = new ApprovalPolicy;
        $this->assertFalse($policy->view($user, $approval));
    }

    public function test_approval_policy_view_allows_admin_on_any(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $otherUser = User::factory()->create(['role' => Role::Manager]);
        $approval = new Approval(['user_id' => $otherUser->id]);

        $policy = new ApprovalPolicy;
        $this->assertTrue($policy->view($admin, $approval));
    }

    // =========================================================================
    // DocumentAttachmentResource exposes size + uploaded_by_name
    // =========================================================================

    public function test_attachment_list_exposes_size_and_uploader_name(): void
    {
        Storage::fake('documents');
        $author = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($author, ['*']);

        $doc = Document::factory()->create(['author_user_id' => $author->id]);

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'size_bytes' => 12345,
            'uploaded_by_user_id' => $author->id,
        ]);

        $response = $this->getJson("/api/documents/{$doc->id}/attachments");

        $response->assertOk();
        $response->assertJsonPath('data.0.size', 12345);
        $response->assertJsonPath('data.0.uploaded_by', $author->id);
        $response->assertJsonStructure([
            'data' => [['id', 'size', 'uploaded_by', 'uploaded_by_name']],
        ]);
    }

    public function test_attachment_upload_stores_size_bytes(): void
    {
        Storage::fake('documents');
        $author = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($author, ['*']);

        $doc = Document::factory()->create(['author_user_id' => $author->id]);
        $file = UploadedFile::fake()->create('test.pdf', 50, 'application/pdf');

        $response = $this->postJson("/api/documents/{$doc->id}/attachments", [
            'kind' => 'other',
            'file' => $file,
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.size'));
        $this->assertGreaterThan(0, $response->json('data.size'));
    }

    // =========================================================================
    // DocumentPolicy.unsign — admin/lawyer only (trivial FE guard alignment)
    // =========================================================================

    public function test_unsign_denied_for_author_without_privilege(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($author, ['*']);

        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => 'signed',
        ]);

        $this->postJson("/api/documents/{$doc->id}/unsign")
            ->assertForbidden();
    }

    public function test_unsign_allowed_for_lawyer(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Sanctum::actingAs($lawyer, ['*']);

        $doc = Document::factory()->create([
            'status' => 'signed',
            'docx_path' => 'contracts/1/contract.docx',
        ]);

        $this->postJson("/api/documents/{$doc->id}/unsign")
            ->assertOk();
    }
}
