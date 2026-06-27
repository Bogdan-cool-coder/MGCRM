<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Regression tests for audit wave 5 bugs fixed in the Документы tab + backend.
 *
 * Covered:
 *  BUG-1  Downloads 401 (FE uses window.open on token-protected URLs)
 *         → Backend: download endpoints honour DocumentPolicy::view for approvers (see BUG-5)
 *  BUG-2  template_id silently ignored — generation always uses master_skeleton
 *  BUG-3  signed_at PATCH silently dropped (not in UpdateDocumentRequest)
 *  BUG-4  Resubmit offered on REJECTED (FE) — tested via canResubmit logic in FE; BE guard verified
 *  BUG-5  Director (active approver) 403 on view/download
 */
class AuditWave5BugsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Template $masterSkeleton;

    private TemplateVersion $skeletonVersion;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        // YAML overlays required by ContractContextBuilder.
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

        // master_skeleton with a real docx version.
        $this->masterSkeleton = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);
        $docxPath = $this->buildDocx('master_skeleton');

        $this->skeletonVersion = TemplateVersion::create([
            'template_id' => $this->masterSkeleton->id,
            'version_number' => 1,
            'docx_path' => $docxPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $this->masterSkeleton->update(['current_version_id' => $this->skeletonVersion->id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildDocx(string $tag): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText("Template: {$tag}");

        $tmp = sys_get_temp_dir()."/wave5_{$tag}_".uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmp);

        $diskPath = "templates/{$tag}/v1_wave5.docx";
        Storage::disk('documents')->put($diskPath, file_get_contents($tmp));
        @unlink($tmp);

        return $diskPath;
    }

    private function fakePdf(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 wave5',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    private function makeDraftDoc(User $author, array $extra = []): Document
    {
        return Document::factory()->create(array_merge([
            'author_user_id' => $author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'status' => ContractStatus::Draft,
        ], $extra));
    }

    // =========================================================================
    // BUG-1: Download endpoints require Bearer auth — accessible to author
    // =========================================================================

    public function test_docx_download_requires_auth(): void
    {
        $doc = $this->makeDraftDoc($this->admin, [
            'docx_path' => 'contracts/1/contract.docx',
        ]);
        Storage::disk('documents')->put('contracts/1/contract.docx', 'fake-docx-content');

        // Unauthenticated → 401
        $this->get("/api/documents/{$doc->id}/download/docx")->assertUnauthorized();
    }

    public function test_docx_download_authenticated_author_succeeds(): void
    {
        $this->fakePdf();
        $doc = $this->makeDraftDoc($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $response = $this->get("/api/documents/{$doc->id}/download/docx");
        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    public function test_attachment_download_requires_auth(): void
    {
        $doc = $this->makeDraftDoc($this->admin);
        Storage::disk('documents')->put('attachments/99/scan.pdf', '%PDF');
        $att = DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan,
            'path' => 'attachments/99/scan.pdf',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        // Unauthenticated → 401
        $this->get("/api/documents/{$doc->id}/attachments/{$att->id}/download")->assertUnauthorized();
    }

    public function test_attachment_download_authenticated_author_succeeds(): void
    {
        $doc = $this->makeDraftDoc($this->admin);
        Storage::disk('documents')->put('attachments/99/scan.pdf', '%PDF-content');
        $att = DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan,
            'path' => 'attachments/99/scan.pdf',
            'original_name' => 'scan.pdf',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->get("/api/documents/{$doc->id}/attachments/{$att->id}/download")->assertOk();
    }

    // =========================================================================
    // BUG-2: template_id respected — does not always fall back to master_skeleton
    // =========================================================================

    public function test_generation_uses_explicit_template_id(): void
    {
        $this->fakePdf();

        // Create a second docx template.
        $altTemplate = Template::factory()->create([
            'code' => 'alt_template',
            'kind' => 'docx',
            'content' => '',
        ]);
        $altPath = $this->buildDocx('alt_template');
        $altVersion = TemplateVersion::create([
            'template_id' => $altTemplate->id,
            'version_number' => 1,
            'docx_path' => $altPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $altTemplate->update(['current_version_id' => $altVersion->id]);

        $doc = $this->makeDraftDoc($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        // POST to direct generate endpoint with template_id.
        $response = $this->postJson("/api/documents/{$doc->id}/generate", [
            'template_id' => $altTemplate->id,
        ]);

        $response->assertOk();

        // The returned template_version on the Document should be the alt template's version.
        $doc->refresh();
        $this->assertSame($altVersion->id, (int) $doc->template_version);
    }

    public function test_generation_without_template_id_uses_master_skeleton(): void
    {
        $this->fakePdf();

        $doc = $this->makeDraftDoc($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $doc->refresh();
        $this->assertSame($this->skeletonVersion->id, (int) $doc->template_version);
    }

    public function test_generation_with_invalid_template_id_returns_422(): void
    {
        $doc = $this->makeDraftDoc($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        // template_id=99999 does not exist in the database.
        $this->postJson("/api/documents/{$doc->id}/generate", [
            'template_id' => 99999,
        ])->assertUnprocessable();
    }

    public function test_deal_generate_endpoint_passes_template_id(): void
    {
        $this->fakePdf();

        $altTemplate = Template::factory()->create([
            'code' => 'deal_alt_template',
            'kind' => 'docx',
            'content' => '',
        ]);
        $altPath = $this->buildDocx('deal_alt_template');
        $altVersion = TemplateVersion::create([
            'template_id' => $altTemplate->id,
            'version_number' => 1,
            'docx_path' => $altPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $altTemplate->update(['current_version_id' => $altVersion->id]);

        $company = \App\Domain\Crm\Models\Company::factory()->create();
        $deal = \App\Domain\Sales\Models\Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/deals/{$deal->id}/documents/generate", [
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'template_id' => $altTemplate->id,
        ]);

        $response->assertOk();

        $docId = $response->json('data.document_id');
        $doc = Document::findOrFail($docId);
        $this->assertSame($altVersion->id, (int) $doc->template_version);
    }

    // =========================================================================
    // BUG-3: signed_at PATCH persists
    // =========================================================================

    public function test_patch_signed_at_persists_on_signed_document(): void
    {
        // A signed document (post-transition — signed_at set by status machine).
        // We want to overwrite the factual date.
        $doc = Document::factory()->create([
            'author_user_id' => $this->admin->id,
            'status' => ContractStatus::Signed,
            'signed_at' => now()->subDays(5),
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $factualDate = '2026-06-01';

        $response = $this->patchJson("/api/documents/{$doc->id}", [
            'signed_at' => $factualDate,
        ]);

        $response->assertOk();

        $doc->refresh();
        $this->assertNotNull($doc->signed_at);
        $this->assertStringStartsWith($factualDate, $doc->signed_at->toDateString());
    }

    public function test_patch_signed_at_null_clears_it(): void
    {
        $doc = Document::factory()->create([
            'author_user_id' => $this->admin->id,
            'status' => ContractStatus::Signed,
            'signed_at' => now(),
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/documents/{$doc->id}", ['signed_at' => null])->assertOk();

        $doc->refresh();
        $this->assertNull($doc->signed_at);
    }

    public function test_patch_signed_at_on_draft_doc_persists(): void
    {
        $doc = $this->makeDraftDoc($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/documents/{$doc->id}", [
            'signed_at' => '2026-05-15',
        ])->assertOk();

        $doc->refresh();
        $this->assertSame('2026-05-15', $doc->signed_at->toDateString());
    }

    // =========================================================================
    // BUG-4: Resubmit from REJECTED must fail (state-machine terminal)
    // =========================================================================

    public function test_submit_from_rejected_returns_422(): void
    {
        $doc = Document::factory()->create([
            'author_user_id' => $this->admin->id,
            'status' => ContractStatus::Rejected,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")->assertUnprocessable();
    }

    public function test_submit_from_needs_rework_succeeds(): void
    {
        // Set up approval route so submit can find approvers.
        $approver = User::factory()->create(['role' => Role::Director]);
        ApprovalRoute::factory()->singleStage([$approver->id])->create();

        $doc = Document::factory()->create([
            'author_user_id' => $this->admin->id,
            'status' => ContractStatus::NeedsRework,
            'docx_path' => 'contracts/1/contract.docx',
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);
        Storage::disk('documents')->put('contracts/1/contract.docx', 'fake');

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/submit")->assertOk();
    }

    // =========================================================================
    // BUG-5: Active approver can view + download document
    // =========================================================================

    public function test_active_approver_can_view_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $director = User::factory()->create(['role' => Role::Director]);

        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => ContractStatus::InReview,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        // Director is an active approver for attempt=1.
        Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $director->id,
            'attempt' => 1,
            'stage_order' => 1,
            'decision' => ApprovalDecision::Pending,
        ]);

        // Create a revision so currentAttempt() returns 1.
        \App\Domain\Contracts\Models\DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 1,
            'attempt' => 1,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/documents/{$doc->id}")->assertOk();
    }

    public function test_active_approver_can_download_docx(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $director = User::factory()->create(['role' => Role::Director]);

        Storage::disk('documents')->put('contracts/42/contract.docx', 'fake-docx');
        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => ContractStatus::InReview,
            'docx_path' => 'contracts/42/contract.docx',
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        Approval::factory()->create([
            'document_id' => $doc->id,
            'user_id' => $director->id,
            'attempt' => 1,
            'stage_order' => 1,
            'decision' => ApprovalDecision::Pending,
        ]);

        \App\Domain\Contracts\Models\DocumentRevision::factory()->create([
            'document_id' => $doc->id,
            'version_number' => 1,
            'attempt' => 1,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->get("/api/documents/{$doc->id}/download/docx")->assertOk();
    }

    public function test_unrelated_user_cannot_view_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $unrelated = User::factory()->create(['role' => Role::Director]);

        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => ContractStatus::InReview,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        // No Approval row for $unrelated.

        Sanctum::actingAs($unrelated, ['*']);

        $this->getJson("/api/documents/{$doc->id}")->assertForbidden();
    }

    public function test_unrelated_user_cannot_download_docx(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $unrelated = User::factory()->create(['role' => Role::Director]);

        Storage::disk('documents')->put('contracts/43/contract.docx', 'fake-docx');
        $doc = Document::factory()->create([
            'author_user_id' => $author->id,
            'status' => ContractStatus::InReview,
            'docx_path' => 'contracts/43/contract.docx',
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        Sanctum::actingAs($unrelated, ['*']);

        $this->get("/api/documents/{$doc->id}/download/docx")->assertForbidden();
    }
}
