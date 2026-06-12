<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ApprovalService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ApprovalService::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAuthor(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    private function makeApprover(?string $role = null): User
    {
        return User::factory()->create(['role' => $role ?? Role::Lawyer->value]);
    }

    private function makeDocWithDocx(User $author): Document
    {
        return Document::factory()->draft()->create([
            'author_user_id' => $author->id,
            'docx_path' => 'documents/test.docx',
        ]);
    }

    private function makeRoute(array $stages, bool $isDefault = true): ApprovalRoute
    {
        return ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => $isDefault,
            'is_active' => true,
            'stages' => $stages,
        ]);
    }

    // =========================================================================
    // Submit tests
    // =========================================================================

    public function test_submit_creates_pending_approvals_for_stage_1(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        $this->service->submit($doc, $author);

        $this->assertDatabaseHas('approvals', [
            'document_id' => $doc->id,
            'attempt' => 1,
            'stage_order' => 1,
            'user_id' => $approver->id,
            'decision' => ApprovalDecision::Pending->value,
        ]);
    }

    public function test_submit_transitions_doc_to_in_review(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        $result = $this->service->submit($doc, $author);

        $this->assertSame(ContractStatus::InReview, $result->status);
    }

    public function test_submit_creates_revision_with_incremented_attempt(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        $this->service->submit($doc, $author);

        $this->assertDatabaseHas('document_revisions', [
            'document_id' => $doc->id,
            'attempt' => 1,
            'version_number' => 1,
        ]);
    }

    public function test_resubmit_after_needs_rework_increments_attempt(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        // First submit
        $this->service->submit($doc, $author);

        // Simulate decide needs_rework
        $approval = Approval::query()->where('document_id', $doc->id)->first();
        $approval->update(['decision' => ApprovalDecision::NeedsRework->value, 'comment' => 'Fix this.', 'decided_at' => now()]);
        $doc->update(['status' => ContractStatus::NeedsRework->value]);
        $doc->refresh();

        // Second submit (resubmit)
        $result = $this->service->submit($doc, $author);

        $this->assertSame(ContractStatus::InReview, $result->status);
        $this->assertDatabaseHas('document_revisions', [
            'document_id' => $doc->id,
            'attempt' => 2,
        ]);
    }

    public function test_submit_without_docx_throws_422(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $author->id,
            'docx_path' => null,
        ]);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        $this->expectException(ValidationException::class);
        $this->service->submit($doc, $author);
    }

    public function test_submit_without_route_throws_422(): void
    {
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        // No route created

        $this->expectException(ValidationException::class);
        $this->service->submit($doc, $author);
    }

    public function test_submit_dispatches_submitted_event(): void
    {
        Event::fake([DocumentSubmittedForApproval::class]);

        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        $this->service->submit($doc, $author);

        Event::assertDispatched(DocumentSubmittedForApproval::class, function (DocumentSubmittedForApproval $e) use ($doc): bool {
            return $e->document->id === $doc->id && $e->attempt === 1;
        });
    }

    public function test_self_approval_guard_on_submit(): void
    {
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        // Author is in stage 1 user_ids
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$author->id], 'min_required' => 1],
        ]);

        $this->expectException(ValidationException::class);
        $this->service->submit($doc, $author);
    }

    // =========================================================================
    // Decide tests
    // =========================================================================

    private function setupForDecide(array $stageUserIds = [], int $minRequired = 1): array
    {
        $author = $this->makeAuthor();
        $approver = User::factory()->create(['role' => Role::Lawyer->value]);
        if (empty($stageUserIds)) {
            $stageUserIds = [$approver->id];
        }
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => $stageUserIds, 'min_required' => $minRequired],
        ]);
        $result = $this->service->submit($doc, $author);

        return ['doc' => $result, 'author' => $author, 'approver' => $approver];
    }

    public function test_approve_below_quorum_keeps_in_review(): void
    {
        $approver2 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver3 = User::factory()->create(['role' => Role::Lawyer->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver2->id, $approver3->id], 'min_required' => 2],
        ]);
        $result = $this->service->submit($doc, $author);

        // Only one of two required approves
        $updated = $this->service->decide($result, $approver2, ApprovalDecision::Approved, null);

        $this->assertSame(ContractStatus::InReview, $updated->status);
    }

    public function test_approve_reaches_quorum_advances_to_stage_2(): void
    {
        $approver1 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver2 = User::factory()->create(['role' => Role::Director->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$approver1->id], 'min_required' => 1],
            ['order' => 2, 'name' => 'Директор', 'user_ids' => [$approver2->id], 'min_required' => 1],
        ]);
        $result = $this->service->submit($doc, $author);

        // Stage 1 approves
        $this->service->decide($result, $approver1, ApprovalDecision::Approved, null);

        // Stage 2 approvals should be created
        $this->assertDatabaseHas('approvals', [
            'document_id' => $doc->id,
            'attempt' => 1,
            'stage_order' => 2,
            'user_id' => $approver2->id,
            'decision' => ApprovalDecision::Pending->value,
        ]);
    }

    public function test_approve_last_stage_transitions_to_approved(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $updated = $this->service->decide($doc, $approver, ApprovalDecision::Approved, null);

        $this->assertSame(ContractStatus::Approved, $updated->status);
    }

    public function test_reject_transitions_to_rejected(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $updated = $this->service->decide($doc, $approver, ApprovalDecision::Rejected, 'Bad contract.');

        $this->assertSame(ContractStatus::Rejected, $updated->status);
    }

    public function test_needs_rework_transitions_to_needs_rework(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $updated = $this->service->decide($doc, $approver, ApprovalDecision::NeedsRework, 'Fix the terms.');

        $this->assertSame(ContractStatus::NeedsRework, $updated->status);
    }

    public function test_decide_creates_remark_on_reject(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $this->service->decide($doc, $approver, ApprovalDecision::Rejected, 'Invalid date.');

        $this->assertDatabaseHas('document_remarks', [
            'document_id' => $doc->id,
            'text' => 'Invalid date.',
        ]);
    }

    public function test_decide_creates_remark_on_needs_rework(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $this->service->decide($doc, $approver, ApprovalDecision::NeedsRework, 'Fix the price.');

        $this->assertDatabaseHas('document_remarks', [
            'document_id' => $doc->id,
            'text' => 'Fix the price.',
        ]);
    }

    public function test_decide_dispatches_decision_event(): void
    {
        Event::fake([ApprovalDecisionMade::class]);
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        $this->service->decide($doc, $approver, ApprovalDecision::Approved, null);

        Event::assertDispatched(ApprovalDecisionMade::class);
    }

    public function test_decide_row_lock_409_when_not_in_review(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => ContractStatus::Approved->value,
        ]);

        $this->expectException(HttpException::class);
        $this->service->decide($doc, $approver, ApprovalDecision::Approved, null);
    }

    public function test_decide_403_when_not_assigned(): void
    {
        ['doc' => $doc] = $this->setupForDecide();
        $outsider = User::factory()->create(['role' => Role::Director->value]);

        $this->expectException(HttpException::class);
        $this->service->decide($doc, $outsider, ApprovalDecision::Approved, null);
    }

    public function test_decide_self_approval_guard(): void
    {
        ['doc' => $doc, 'author' => $author] = $this->setupForDecide();

        $this->expectException(HttpException::class);
        $this->service->decide($doc, $author, ApprovalDecision::Approved, null);
    }

    public function test_decide_422_when_already_decided(): void
    {
        ['doc' => $doc, 'approver' => $approver] = $this->setupForDecide();

        // First decide
        $this->service->decide($doc, $approver, ApprovalDecision::Approved, null);

        // Document now Approved — try to decide again (not InReview)
        $this->expectException(HttpException::class);
        $doc->refresh();
        $this->service->decide($doc, $approver, ApprovalDecision::Approved, null);
    }

    // =========================================================================
    // Quorum and multi-stage tests
    // =========================================================================

    public function test_quorum_unanimous(): void
    {
        $approver1 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver2 = User::factory()->create(['role' => Role::Lawyer->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        // min_required = 2 = count(user_ids) → unanimous
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver1->id, $approver2->id], 'min_required' => 2],
        ]);
        $submitted = $this->service->submit($doc, $author);

        // First approve: quorum not reached
        $after1 = $this->service->decide($submitted, $approver1, ApprovalDecision::Approved, null);
        $this->assertSame(ContractStatus::InReview, $after1->status);

        // Second approve: quorum reached → Approved
        $after2 = $this->service->decide($after1, $approver2, ApprovalDecision::Approved, null);
        $this->assertSame(ContractStatus::Approved, $after2->status);
    }

    public function test_quorum_partial(): void
    {
        $approver1 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver2 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver3 = User::factory()->create(['role' => Role::Lawyer->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        // min_required = 1 out of 3
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver1->id, $approver2->id, $approver3->id], 'min_required' => 1],
        ]);
        $submitted = $this->service->submit($doc, $author);

        // One approve is enough
        $updated = $this->service->decide($submitted, $approver1, ApprovalDecision::Approved, null);

        $this->assertSame(ContractStatus::Approved, $updated->status);
    }

    public function test_stage_2_approvals_not_created_until_stage_1_complete(): void
    {
        $approver1 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver2 = User::factory()->create(['role' => Role::Director->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver1->id], 'min_required' => 1],
            ['order' => 2, 'name' => 'Stage 2', 'user_ids' => [$approver2->id], 'min_required' => 1],
        ]);
        $this->service->submit($doc, $author);

        // Stage 2 approvals must NOT exist yet
        $this->assertDatabaseMissing('approvals', [
            'document_id' => $doc->id,
            'stage_order' => 2,
        ]);
    }

    public function test_full_2stage_happy_path(): void
    {
        $approver1 = User::factory()->create(['role' => Role::Lawyer->value]);
        $approver2 = User::factory()->create(['role' => Role::Director->value]);
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$approver1->id], 'min_required' => 1],
            ['order' => 2, 'name' => 'Директор', 'user_ids' => [$approver2->id], 'min_required' => 1],
        ]);

        $submitted = $this->service->submit($doc, $author);
        $this->assertSame(ContractStatus::InReview, $submitted->status);

        // Stage 1 approve
        $afterStage1 = $this->service->decide($submitted, $approver1, ApprovalDecision::Approved, null);
        $this->assertSame(ContractStatus::InReview, $afterStage1->status);

        // Stage 2 approve
        $afterStage2 = $this->service->decide($afterStage1, $approver2, ApprovalDecision::Approved, null);
        $this->assertSame(ContractStatus::Approved, $afterStage2->status);
    }

    public function test_needs_rework_cycle_resets_to_stage_1_with_new_attempt(): void
    {
        Event::fake([DocumentSubmittedForApproval::class, ApprovalDecisionMade::class]);

        $approver = $this->makeApprover();
        $author = $this->makeAuthor();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        // First attempt
        $submitted = $this->service->submit($doc, $author);
        $afterNeedsRework = $this->service->decide($submitted, $approver, ApprovalDecision::NeedsRework, 'Fix it.');
        $this->assertSame(ContractStatus::NeedsRework, $afterNeedsRework->status);

        // Resubmit
        $resubmitted = $this->service->submit($afterNeedsRework, $author);
        $this->assertSame(ContractStatus::InReview, $resubmitted->status);

        // New pending approval must exist for attempt=2
        $this->assertDatabaseHas('approvals', [
            'document_id' => $doc->id,
            'attempt' => 2,
            'stage_order' => 1,
            'user_id' => $approver->id,
            'decision' => ApprovalDecision::Pending->value,
        ]);
    }
}
