<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Jobs\SendTelegramApprovalCardJob;
use App\Domain\Notification\Services\ApprovalNotificationService;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    // =========================================================================
    // Э3 finding 4 — the Telegram approval-card idempotency key
    // (documents.telegram_message_id) must be RESET when a round ends, so the
    // next card (resubmit / next stage) is not silently suppressed.
    // =========================================================================

    public function test_needs_rework_resets_telegram_message_id(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Simulate the stage-1 card having been delivered (id stored).
        $doc->forceFill(['telegram_message_id' => 555])->save();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'needs_rework',
            'comment' => 'Fix it.',
        ])->assertOk();

        // Leaving in_review must clear the key so a resubmit can post afresh.
        $this->assertNull(
            $doc->fresh()->telegram_message_id,
            'needs_rework must reset telegram_message_id.',
        );
    }

    public function test_rejected_resets_telegram_message_id(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();
        $doc->forceFill(['telegram_message_id' => 777])->save();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'rejected',
            'comment' => 'No.',
        ])->assertOk();

        $this->assertNull($doc->fresh()->telegram_message_id);
    }

    public function test_stage_advance_resets_telegram_message_id_for_next_card(): void
    {
        // Fake the queue so the stage-2 card job does not run synchronously and
        // immediately re-store a fresh id — we want to observe the RESET itself
        // (the seam that lets the next card post at all). Without the reset the id
        // would still be the stale stage-1 value and the SendTelegramApprovalCardJob
        // pre-check would suppress the stage-2 card forever.
        Queue::fake();

        $author = $this->makeAuthor();
        $stage1Approver = $this->makeApprover();
        $stage2Approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$stage1Approver->id], 'min_required' => 1],
            ['order' => 2, 'name' => 'Stage 2', 'user_ids' => [$stage2Approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();
        $doc->forceFill(['telegram_message_id' => 111])->save();

        // Stage-1 approval → quorum reached → advance to stage 2 (document STAYS
        // in_review, so the transition-based reset never fires). The stage-advance
        // reset must clear the key so stage 2's card can post.
        Sanctum::actingAs($stage1Approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])->assertOk();

        $this->assertSame('in_review', $doc->fresh()->status->value, 'Doc stays in_review across the advance.');
        $this->assertNull(
            $doc->fresh()->telegram_message_id,
            'A stage advance must reset telegram_message_id so the next-stage card can send.',
        );
    }

    public function test_card_resends_after_needs_rework_and_resubmit(): void
    {
        // End-to-end: the resubmit card is NOT suppressed because the key was
        // reset on needs_rework. Drives the actual job twice via the notifier.
        config()->set('crm.telegram.web_base_url', 'https://crm.test');

        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);

        // First send: key null → card posts, stores id.
        $doc->forceFill(['status' => 'in_review', 'telegram_message_id' => null])->save();
        $notifier = \Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendToChat')->once()->andReturn(900);
        $this->app->instance(TelegramNotifier::class, $notifier);
        (new SendTelegramApprovalCardJob($doc->id, '-100999', 'card 1'))
            ->handle($notifier, app(ApprovalNotificationService::class));
        $this->assertSame(900, (int) $doc->fresh()->telegram_message_id);

        // Round ends (needs_rework clears the key), resubmit re-enters in_review.
        app(DocumentService::class)
            ->transition($doc->fresh(), ContractStatus::NeedsRework, $approver->id);
        $this->assertNull($doc->fresh()->telegram_message_id);

        // Second send: key null again → the card posts a SECOND time (regression
        // guard: before the fix the stale id suppressed it forever).
        \Mockery::close();
        $notifier2 = \Mockery::mock(TelegramNotifier::class);
        $notifier2->shouldReceive('sendToChat')->once()->andReturn(901);
        $this->app->instance(TelegramNotifier::class, $notifier2);
        (new SendTelegramApprovalCardJob($doc->id, '-100999', 'card 2'))
            ->handle($notifier2, app(ApprovalNotificationService::class));

        $this->assertSame(901, (int) $doc->fresh()->telegram_message_id);
    }
}
