<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests for the /api/documents/{id}/approval-summary endpoint.
 *
 * Verifies the ApprovalSummaryResource emits all fields required by the FE
 * ApprovalSummaryDto: id, document_id, decision, comment, is_current_user_approver.
 *
 * Also verifies ApprovalResource emits a flat user_name string alongside the
 * nested user object (ApprovalVoteDto.user_name).
 *
 * Finally, verifies that with TemplateVersionSeeder run, generation no longer 422s.
 */
class ApprovalSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    // ---- Helpers ----

    private function makeAuthor(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    private function makeApprover(): User
    {
        return User::factory()->create(['role' => Role::Lawyer->value]);
    }

    private function makeRoute(array $stages): ApprovalRoute
    {
        return ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => $stages,
        ]);
    }

    private function makeDocWithDocx(User $author): Document
    {
        return Document::factory()->draft()->create([
            'author_user_id' => $author->id,
            'docx_path' => 'documents/test.docx',
        ]);
    }

    // =========================================================================
    // ApprovalSummaryResource — required FE fields
    // =========================================================================

    public function test_approval_summary_emits_document_id(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertSame($doc->id, $response->json('data.document_id'));
    }

    public function test_approval_summary_emits_null_decision_while_in_review(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // While still in_review, aggregate decision must be null.
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertNull($response->json('data.decision'));
    }

    public function test_approval_summary_emits_null_comment_when_no_votes(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertNull($response->json('data.comment'));
    }

    public function test_pending_approver_gets_is_current_user_approver_true(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Now check summary as the approver — should be true.
        Sanctum::actingAs($approver, ['*']);
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertTrue($response->json('data.is_current_user_approver'));
    }

    public function test_non_approver_gets_is_current_user_approver_false(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $nonApprover = User::factory()->create(['role' => Role::Manager]);
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Admin sees the summary but is not an approver.
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertFalse($response->json('data.is_current_user_approver'));
    }

    // =========================================================================
    // BUG-1: active approvers (non-admin/non-lawyer/non-author) must get 200
    // =========================================================================

    /**
     * A director who is a stage-2 approver must be able to see the approval
     * summary (200) even though they are not admin/lawyer/author.
     *
     * The route is submitted by the author; stage-2 user_ids contains the
     * director.  Stage-2 Approval rows are not yet created at submit time
     * (only stage-1 rows are created), so we verify both the case where the
     * user already has a row (stage-1 flow) and the case for stage-N > 1.
     *
     * Here we wire the director as a stage-1 approver so an Approval row
     * exists immediately after submit — matching the policy check.
     */
    public function test_stage_approver_director_can_view_approval_summary(): void
    {
        $author = $this->makeAuthor();
        $director = User::factory()->create(['role' => Role::Director]);
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Директор', 'user_ids' => [$director->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Director is stage-1 approver — should get 200, not 403.
        Sanctum::actingAs($director, ['*']);
        $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();
    }

    public function test_unrelated_director_cannot_view_approval_summary(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $unrelatedDirector = User::factory()->create(['role' => Role::Director]);
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Unrelated director has no Approval row — must still get 403.
        Sanctum::actingAs($unrelatedDirector, ['*']);
        $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertForbidden();
    }

    // =========================================================================
    // BUG-2: author who is also an approver must get is_current_user_approver=false
    // =========================================================================

    /**
     * When the document author is also listed in an active stage's user_ids,
     * is_current_user_approver must be false because ApprovalService::decide()
     * always blocks self-approval with a 403.
     *
     * The submit itself would 422 if the author is in stage-1 (self-approval
     * guard), so we test with a two-stage route: author is in stage-2.
     * We advance the route to stage-2 by approving stage-1, then confirm the
     * flag is still false for the author.
     */
    public function test_author_as_stage2_approver_gets_is_current_user_approver_false(): void
    {
        $author = $this->makeAuthor();
        $stage1Approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$stage1Approver->id], 'min_required' => 1],
            // Author is listed as stage-2 approver — self-approval guard must still block.
            ['order' => 2, 'name' => 'Stage 2', 'user_ids' => [$author->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        // Stage-1 approver approves → advances to stage-2 (author's stage).
        Sanctum::actingAs($stage1Approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])->assertOk();

        // Stage-2 is now active; author has a pending Approval row but cannot decide.
        // is_current_user_approver must be false.
        Sanctum::actingAs($author, ['*']);
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertFalse(
            $response->json('data.is_current_user_approver'),
            'Author who is also a stage-2 approver must get is_current_user_approver=false'
        );
    }

    public function test_after_deciding_approver_is_no_longer_current_user_approver(): void
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
        // Approve the document.
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])->assertOk();

        // After deciding, is_current_user_approver must be false (no pending approval).
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $this->assertFalse($response->json('data.is_current_user_approver'));
    }

    public function test_approval_summary_stage_has_required_fields(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        // FE ApprovalStageDto shape: id, order, name, min_required, total, approvals, is_active, is_done.
        $response->assertJsonStructure([
            'data' => [
                'id',
                'document_id',
                'attempt',
                'current_stage_order',
                'total_stages',
                'is_current_user_approver',
                'decision',
                'comment',
                'stages' => [
                    [
                        'id',
                        'order',
                        'name',
                        'min_required',
                        'total',
                        'is_active',
                        'is_done',
                        'approvals',
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // ApprovalResource — flat user_name field
    // =========================================================================

    public function test_approval_vote_carries_user_name_string(): void
    {
        $author = $this->makeAuthor();
        $approver = $this->makeApprover();
        $approver->update(['full_name' => 'Иван Юрист']);
        $doc = $this->makeDocWithDocx($author);
        $this->makeRoute([
            ['order' => 1, 'name' => 'Stage 1', 'user_ids' => [$approver->id], 'min_required' => 1],
        ]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/documents/{$doc->id}/decide", ['decision' => 'approved'])->assertOk();

        Sanctum::actingAs($author, ['*']);
        $response = $this->getJson("/api/documents/{$doc->id}/approval-summary")->assertOk();

        $stages = $response->json('data.stages');
        $votes = $stages[0]['approvals'] ?? [];
        $this->assertNotEmpty($votes);
        $this->assertArrayHasKey('user_name', $votes[0]);
        $this->assertSame('Иван Юрист', $votes[0]['user_name']);
    }

    // =========================================================================
    // Generation smoke: seeded template version resolves (no 422)
    // =========================================================================

    public function test_generation_works_when_template_version_seeded(): void
    {
        Storage::fake('documents');

        // Seed a YAML template required by YamlTemplateParser.
        Template::factory()->create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'content' => "name: MacroCRM\n",
        ]);
        Template::factory()->create([
            'code' => 'country_uz',
            'kind' => 'yaml',
            'content' => "name_full: Узбекистан\ncurrency_code: UZS\n",
        ]);

        // Build a minimal PHPWord ${...} docx.
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('${contract.number}');
        $section->addText('${total_in_words}');
        $tmpPath = sys_get_temp_dir().'/test_seed_'.uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = 'templates/master_skeleton/seed_v1.docx';
        Storage::disk('documents')->put($diskPath, (string) file_get_contents($tmpPath));
        @unlink($tmpPath);

        $admin = User::factory()->create(['role' => Role::Admin]);

        // Create master_skeleton template with a version (simulating TemplateVersionSeeder).
        $template = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);
        $version = TemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'docx_path' => $diskPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $admin->id,
            'created_at' => now(),
        ]);
        $template->update(['current_version_id' => $version->id]);

        // Fake Gotenberg.
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 test',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $doc = Document::factory()->draft()->create([
            'author_user_id' => $admin->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 10000,
        ]);

        // Generate must succeed (no 422 «Шаблон не загружен»).
        $response = $this->postJson("/api/documents/{$doc->id}/generate");
        $response->assertOk()
            ->assertJsonStructure(['data' => ['document_id', 'number', 'docx_url', 'pdf_url']]);
    }

    public function test_submit_after_generate_moves_to_in_review(): void
    {
        Storage::fake('documents');

        Template::factory()->create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'content' => "name: MacroCRM\n",
        ]);
        Template::factory()->create([
            'code' => 'country_uz',
            'kind' => 'yaml',
            'content' => "name_full: Узбекистан\ncurrency_code: UZS\n",
        ]);

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('${contract.number}');
        $tmpPath = sys_get_temp_dir().'/test_seed2_'.uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = 'templates/master_skeleton/seed_v1.docx';
        Storage::disk('documents')->put($diskPath, (string) file_get_contents($tmpPath));
        @unlink($tmpPath);

        $author = User::factory()->create(['role' => Role::Manager]);
        $approver = User::factory()->create(['role' => Role::Lawyer->value]);

        $template = Template::factory()->create(['code' => 'master_skeleton', 'kind' => 'docx', 'content' => '']);
        $version = TemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'docx_path' => $diskPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $author->id,
            'created_at' => now(),
        ]);
        $template->update(['current_version_id' => $version->id]);

        Http::fake([
            '*forms/libreoffice/convert*' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [
                ['order' => 1, 'name' => 'Юрист', 'user_ids' => [$approver->id], 'min_required' => 1],
            ],
        ]);

        Sanctum::actingAs($author, ['*']);
        $doc = Document::factory()->draft()->create([
            'author_user_id' => $author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 10000,
        ]);

        // generate → sets docx_path
        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        // submit → moves to in_review
        $submitResponse = $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();
        $this->assertSame('in_review', $submitResponse->json('data.status'));

        // approve → approved
        Sanctum::actingAs($approver, ['*']);
        $decideResponse = $this->postJson("/api/documents/{$doc->id}/decide", [
            'decision' => 'approved',
        ])->assertOk();
        $this->assertSame('approved', $decideResponse->json('data.status'));
    }
}
