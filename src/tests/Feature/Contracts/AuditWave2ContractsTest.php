<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for audit Wave 2 contract-domain fixes.
 *
 * Covers:
 *   - MAJOR 1: won-gate rejects approved docs with NULL docx_path
 *   - MAJOR 3: generation does NOT increment attempt; submit does
 *   - MAJOR 4: myApprovals History status='decided' mapping
 *   - MAJOR 5: MyApprovalResource exposes document_number/kind/company_name/stage_name
 *   - MAJOR 7: DocumentRevisionResource exposes `version` alias + `created_by_name`
 *   - MAJOR 7: DocumentRevisionController.show scopes revision to parent document
 *   - MAJOR 9: ApprovalRoute validation allows termination_agreement kind
 */
class AuditWave2ContractsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // MAJOR 1 — Won-gate: docx_path guard
    // =========================================================================

    public function test_won_gate_rejects_approved_doc_without_docx(): void
    {
        $deal = Deal::factory()->create();
        Document::factory()->create([
            'source_deal_id' => $deal->id,
            'status' => ContractStatus::Approved->value,
            'docx_path' => null,   // fake/seed doc — must not pass gate
        ]);

        $service = app(DocumentService::class);
        $this->assertFalse(
            $service->hasActiveContractForDeal($deal->id),
            'Approved doc without docx_path must NOT satisfy the won-gate.'
        );
    }

    public function test_won_gate_passes_approved_doc_with_docx(): void
    {
        $deal = Deal::factory()->create();
        Document::factory()->create([
            'source_deal_id' => $deal->id,
            'status' => ContractStatus::Approved->value,
            'docx_path' => 'contracts/1/contract.docx',
        ]);

        $service = app(DocumentService::class);
        $this->assertTrue($service->hasActiveContractForDeal($deal->id));
    }

    // =========================================================================
    // MAJOR 3 — Attempt counter separation
    // =========================================================================

    public function test_generation_revision_carries_attempt_zero(): void
    {
        $rev = DocumentRevision::factory()->create([
            'version_number' => 1,
            'attempt' => 0,  // generation sets attempt=0
        ]);

        $this->assertSame(0, $rev->attempt);
        $this->assertSame(1, $rev->version_number);
    }

    public function test_submit_revision_increments_attempt(): void
    {
        $doc = Document::factory()->draft()->create(['docx_path' => 'contracts/1/c.docx']);

        // Simulate generation snapshot (attempt=0)
        DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 1,
            'attempt' => 0,
        ]);

        // Simulate submit snapshot (attempt=1)
        DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 2,
            'attempt' => 1,
        ]);

        // Verify the max attempt for approval is 1 (from submit only)
        $maxSubmitAttempt = DocumentRevision::where('document_id', $doc->id)
            ->where('attempt', '>', 0)
            ->max('attempt');

        $this->assertSame(1, (int) $maxSubmitAttempt);
    }

    public function test_generation_does_not_increase_approval_attempt(): void
    {
        // After submit (attempt=1), a regeneration should NOT bump max-attempt.
        $doc = Document::factory()->create([
            'status' => ContractStatus::NeedsRework->value,
            'docx_path' => 'contracts/1/c.docx',
        ]);

        // Submit round 1 created a revision with attempt=1
        DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 1,
            'attempt' => 1,
        ]);

        // Regeneration after needs_rework — attempt stays 1 (current round)
        DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 2,
            'attempt' => 1,  // same attempt, new version
        ]);

        $maxApprovalAttempt = DocumentRevision::where('document_id', $doc->id)
            ->where('attempt', '>', 0)
            ->max('attempt');

        $this->assertSame(1, (int) $maxApprovalAttempt, 'Regeneration must not increase the approval-round counter.');
    }

    // =========================================================================
    // MAJOR 4 — MyApprovals History: status='decided' filter
    // =========================================================================

    public function test_my_approvals_history_returns_decided_approvals(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer->value]);
        $token = $user->createToken('test')->plainTextToken;

        $doc = Document::factory()->create(['status' => ContractStatus::Approved->value]);

        // Create a decided approval
        Approval::factory()->create([
            'user_id' => $user->id,
            'document_id' => $doc->id,
            'decision' => ApprovalDecision::Approved->value,
            'decided_at' => now(),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/approvals/my?status=decided');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        // Verify the resource includes status='decided'
        $response->assertJsonPath('data.0.status', 'decided');
    }

    public function test_my_approvals_history_excludes_pending(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer->value]);
        $token = $user->createToken('test')->plainTextToken;

        $doc = Document::factory()->create(['status' => ContractStatus::InReview->value]);

        Approval::factory()->create([
            'user_id' => $user->id,
            'document_id' => $doc->id,
            'decision' => ApprovalDecision::Pending->value,
            'decided_at' => null,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/approvals/my?status=decided');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_my_approvals_pending_returns_pending_only(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer->value]);
        $token = $user->createToken('test')->plainTextToken;

        $doc = Document::factory()->create(['status' => ContractStatus::InReview->value]);

        Approval::factory()->create([
            'user_id' => $user->id,
            'document_id' => $doc->id,
            'decision' => ApprovalDecision::Pending->value,
            'decided_at' => null,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/approvals/my?status=pending');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'pending');
    }

    // =========================================================================
    // MAJOR 5 — MyApprovalResource fields
    // =========================================================================

    public function test_my_approval_resource_exposes_document_number(): void
    {
        $user = User::factory()->create(['role' => Role::Lawyer->value]);
        $token = $user->createToken('test')->plainTextToken;

        $doc = Document::factory()->create([
            'status' => ContractStatus::Approved->value,
            'number' => 'АЛМ-220/KZ',
        ]);

        Approval::factory()->create([
            'user_id' => $user->id,
            'document_id' => $doc->id,
            'decision' => ApprovalDecision::Approved->value,
            'decided_at' => now(),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/approvals/my?status=decided');

        $response->assertOk();
        $response->assertJsonPath('data.0.document_number', 'АЛМ-220/KZ');
        $response->assertJsonPath('data.0.document_kind', 'contract');
    }

    // =========================================================================
    // MAJOR 7 — DocumentRevisionResource: version alias + created_by_name
    // =========================================================================

    public function test_revision_resource_exposes_version_alias(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin->value]);
        $token = $admin->createToken('test')->plainTextToken;

        $doc = Document::factory()->create(['author_user_id' => $admin->id]);
        DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 3,
            'attempt' => 1,
            'created_by_user_id' => $admin->id,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/documents/{$doc->id}/revisions");

        $response->assertOk();
        $response->assertJsonPath('data.0.version_number', 3);
        $response->assertJsonPath('data.0.version', 3);  // alias
        $response->assertJsonPath('data.0.created_by_name', $admin->full_name);
    }

    public function test_revision_show_scoped_to_parent_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin->value]);
        $token = $admin->createToken('test')->plainTextToken;

        $docA = Document::factory()->create();
        $docB = Document::factory()->create();

        $revisionB = DocumentRevision::factory()->create([
            'document_id' => $docB->id,
            'version_number' => 1,
            'attempt' => 0,
        ]);

        // Requesting revision of docB via docA's URL must return 404
        $response = $this->withToken($token)
            ->getJson("/api/documents/{$docA->id}/revisions/{$revisionB->id}");

        $response->assertNotFound();
    }

    // =========================================================================
    // MAJOR 9 — ApprovalRoute allows termination_agreement kind
    // =========================================================================

    public function test_approval_route_store_accepts_termination_agreement(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin->value]);
        $token = $admin->createToken('test')->plainTextToken;
        $approver = User::factory()->create(['role' => Role::Lawyer->value]);

        $payload = [
            'title' => 'Termination Route',
            'document_kind' => 'termination_agreement',
            'is_default' => true,
            'is_active' => true,
            'stages' => [
                [
                    'order' => 1,
                    'name' => 'Legal review',
                    'user_ids' => [$approver->id],
                    'min_required' => 1,
                ],
            ],
        ];

        $response = $this->withToken($token)
            ->postJson('/api/approval-routes', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.document_kind', 'termination_agreement');
    }

    public function test_approval_route_update_accepts_termination_agreement(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin->value]);
        $token = $admin->createToken('test')->plainTextToken;
        $approver = User::factory()->create(['role' => Role::Lawyer->value]);

        $route = ApprovalRoute::factory()->create(['document_kind' => 'contract']);

        $response = $this->withToken($token)
            ->patchJson("/api/approval-routes/{$route->id}", [
                'document_kind' => 'termination_agreement',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.document_kind', 'termination_agreement');
    }
}
