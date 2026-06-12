<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
    // Auth / Policy
    // =========================================================================

    public function test_submit_unauthenticated_401(): void
    {
        $doc = Document::factory()->draft()->create(['docx_path' => 'x.docx']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertUnauthorized();
    }

    public function test_submit_non_author_403(): void
    {
        $owner = $this->makeAuthor();
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = $this->makeDocWithDocx($owner);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")
            ->assertForbidden();
    }

    // =========================================================================
    // Happy paths
    // =========================================================================

    public function test_full_happy_path_1stage(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_full_happy_path_2stage(): void
    {
        $author = $this->makeAuthor();
        $approver1 = $this->makeApprover(Role::Lawyer->value);
        $approver2 = $this->makeApprover(Role::Director->value);
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$approver1->id], 'min_required' => 1],
            ['order' => 2, 'name' => 'Директор', 'user_ids' => [$approver2->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Stage 1 decide
        Sanctum::actingAs($approver1, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review'); // still in review (stage 2 pending)

        // Stage 2 must have pending
        $this->assertDatabaseHas('approvals', [
            'document_id' => $doc->id,
            'stage_order' => 2,
            'decision' => 'pending',
        ]);

        // Stage 2 decide
        Sanctum::actingAs($approver2, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_reject_flow(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'rejected',
            'comment' => 'Invalid terms.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('document_remarks', [
            'document_id' => $doc->id,
            'text' => 'Invalid terms.',
        ]);
    }

    public function test_needs_rework_and_resubmit(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'needs_rework',
            'comment' => 'Fix the price.',
        ])->assertOk()->assertJsonPath('data.status', 'needs_rework');

        // Resubmit
        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        // attempt=2
        $this->assertDatabaseHas('approvals', [
            'document_id' => $doc->id,
            'attempt' => 2,
            'stage_order' => 1,
            'decision' => 'pending',
        ]);
    }

    // =========================================================================
    // Approval summary
    // =========================================================================

    public function test_approval_summary_returns_stages_and_counts(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'current_stage_order',
                'total_stages',
                'attempt',
                'can_resubmit',
                'stages' => [['order', 'name', 'pending_count', 'approved_count', 'is_active']],
            ],
        ]);

        $this->assertSame(1, $response->json('data.total_stages'));
        $this->assertSame(1, $response->json('data.stages.0.pending_count'));
    }

    // =========================================================================
    // My approvals
    // =========================================================================

    public function test_my_approvals_returns_pending_for_current_user(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        Sanctum::actingAs($approver, ['*']);
        $response = $this->getJson('/api/approvals/my?status=pending')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('pending', $response->json('data.0.decision'));
    }

    // =========================================================================
    // ApprovalRoute CRUD
    // =========================================================================

    public function test_create_approval_route_admin_201(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $lawyer = User::factory()->create(['role' => Role::Lawyer->value]);

        $this->postJson('/api/approval-routes', [
            'title' => 'My Route',
            'document_kind' => 'contract',
            'is_default' => true,
            'stages' => [
                ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$lawyer->id], 'min_required' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'My Route');
    }

    public function test_create_approval_route_manager_403(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/approval-routes', [
            'title' => 'My Route',
            'document_kind' => 'contract',
            'is_default' => true,
            'stages' => [
                ['order' => 1, 'name' => 'Stage', 'user_ids' => [1], 'min_required' => 1],
            ],
        ])->assertForbidden();
    }

    public function test_update_approval_route(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $approver = $this->makeApprover();
        $route = $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/approval-routes/{$route->id}", [
            'title' => 'Updated Title',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_delete_approval_route_soft(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $approver = $this->makeApprover();
        $route = $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/approval-routes/{$route->id}")->assertNoContent();

        // Still in DB but is_active = false
        $this->assertDatabaseHas('approval_routes', [
            'id' => $route->id,
            'is_active' => false,
        ]);
    }

    // =========================================================================
    // Guards
    // =========================================================================

    public function test_decide_without_comment_on_reject_422(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'rejected',
            // no comment
        ])->assertUnprocessable();
    }

    public function test_author_cannot_decide_own_document(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Author tries to decide — policy blocks at 403
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_events_dispatched_on_submit_and_decide(): void
    {
        Event::fake([DocumentSubmittedForApproval::class, ApprovalDecisionMade::class]);

        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();
        Event::assertDispatched(DocumentSubmittedForApproval::class);

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])->assertOk();
        Event::assertDispatched(ApprovalDecisionMade::class);
    }
}
