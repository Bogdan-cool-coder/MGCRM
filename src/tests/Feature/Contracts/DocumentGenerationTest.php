<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Feature tests for S2.4 document generation endpoints.
 *
 * Gotenberg is ALWAYS mocked via Http::fake() — no real HTTP calls.
 * PHPWord template is created programmatically — no binary fixtures.
 */
class DocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $otherUser;

    private Template $masterSkeleton;

    private TemplateVersion $templateVersion;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->manager = User::factory()->create(['role' => Role::Manager]);
        $this->otherUser = User::factory()->create(['role' => Role::Manager]);

        // Seed YAML templates required by YamlTemplateParser.
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

        // Create master_skeleton template + docx version.
        $this->masterSkeleton = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);

        $docxPath = $this->createMinimalDocx();

        $this->templateVersion = TemplateVersion::create([
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

        $this->masterSkeleton->update(['current_version_id' => $this->templateVersion->id]);
    }

    // ---- Helpers ----

    private function createMinimalDocx(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Номер: ${contract.number}');
        $section->addText('Итого прописью: ${total_in_words}');

        $tmpPath = sys_get_temp_dir().'/feat_template_'.uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = 'templates/master_skeleton/v1_feat.docx';
        Storage::disk('documents')->put($diskPath, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $diskPath;
    }

    private function fakePdf(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 test pdf',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    private function makeDocument(User $author, array $overrides = []): Document
    {
        return Document::factory()->create(array_merge([
            'author_user_id' => $author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'status' => ContractStatus::Draft,
        ], $overrides));
    }

    // ---- generate via /documents/{id}/generate ----

    public function test_generate_via_document_endpoint(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['document_id', 'number', 'docx_url', 'pdf_url', 'warnings']]);

        $this->assertNotNull($response->json('data.number'));
        $this->assertNotNull($response->json('data.docx_url'));
        $this->assertNotNull($response->json('data.pdf_url'));
    }

    public function test_generate_sets_number_in_correct_format(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $number = $response->json('data.number');
        $this->assertMatchesRegularExpression('/^[А-ЯA-Z]+-\d+\/[A-Z]+$/', $number);
    }

    public function test_status_remains_draft_after_generation(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $this->assertSame(ContractStatus::Draft, $doc->fresh()->status);
    }

    public function test_revision_created_after_generation(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $this->assertDatabaseHas('document_revisions', [
            'document_id' => $doc->id,
            'version_number' => 1,
        ]);
    }

    public function test_repeated_generation_does_not_change_number(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $r1 = $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();
        $number1 = $r1->json('data.number');

        Http::fake([
            '*forms/libreoffice/convert*' => Http::response('%PDF-1.4 second', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $r2 = $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();
        $number2 = $r2->json('data.number');

        $this->assertSame($number1, $number2);
    }

    public function test_second_generation_increments_revision_version(): void
    {
        $this->fakePdf();
        $doc = $this->makeDocument($this->admin, ['number' => 'ТАШ-220/UZ', 'city_code' => 'ТАШ']);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        Http::fake([
            '*forms/libreoffice/convert*' => Http::response('%PDF-1.4 v2', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $revisions = DocumentRevision::where('document_id', $doc->id)->orderBy('version_number')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(2, $revisions[1]->version_number);
    }

    // ---- Generate via deal entry point ----

    public function test_generate_via_deal_endpoint(): void
    {
        $this->fakePdf();

        $company = Company::factory()->create();
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/deals/{$deal->id}/documents/generate", [
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['document_id', 'number']]);
    }

    // ---- Generate via company entry point ----

    public function test_generate_via_company_endpoint(): void
    {
        $this->fakePdf();

        $company = Company::factory()->create();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/documents/generate", [
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['document_id', 'number']]);
    }

    // ---- Auth / policy ----

    public function test_unauthenticated_returns_401(): void
    {
        $doc = $this->makeDocument($this->admin);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertUnauthorized();
    }

    public function test_another_user_cannot_generate_other_users_document(): void
    {
        $this->fakePdf();

        $doc = $this->makeDocument($this->manager); // manager owns the doc
        Sanctum::actingAs($this->otherUser, ['*']); // different manager

        $this->postJson("/api/documents/{$doc->id}/generate")->assertForbidden();
    }

    public function test_admin_can_generate_any_document(): void
    {
        $this->fakePdf();

        $doc = $this->makeDocument($this->manager);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();
    }

    // ---- Download endpoints ----

    public function test_download_docx_returns_file(): void
    {
        $this->fakePdf();

        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $response = $this->get("/api/documents/{$doc->id}/download/docx");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    public function test_download_pdf_returns_file(): void
    {
        $this->fakePdf();

        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $response = $this->get("/api/documents/{$doc->id}/download/pdf");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    public function test_download_404_when_not_generated(): void
    {
        $doc = $this->makeDocument($this->admin, ['docx_path' => null, 'pdf_path' => null]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->get("/api/documents/{$doc->id}/download/docx")->assertNotFound();
        $this->get("/api/documents/{$doc->id}/download/pdf")->assertNotFound();
    }

    public function test_422_when_city_missing_for_numbering(): void
    {
        $doc = $this->makeDocument($this->admin, ['city' => '']);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate");

        $response->assertStatus(422);

        // The error message mentions city (check case-insensitively via decoded JSON)
        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('город', mb_strtolower($body ?? ''));
    }

    public function test_422_when_template_not_uploaded(): void
    {
        $this->masterSkeleton->update(['current_version_id' => null]);
        TemplateVersion::truncate();

        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate");

        $response->assertStatus(422);
    }

    public function test_warnings_returned_when_template_not_checked(): void
    {
        $this->templateVersion->update(['pdf_ok' => null]);
        $this->fakePdf();

        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $this->assertContains('template_not_checked', $response->json('data.warnings'));
    }

    public function test_document_resource_contains_download_urls_after_generation(): void
    {
        $this->fakePdf();

        $doc = $this->makeDocument($this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/generate")->assertOk();

        $show = $this->getJson("/api/documents/{$doc->id}")->assertOk();

        $this->assertNotNull($show->json('data.download_urls'));
        $this->assertNotNull($show->json('data.download_urls.docx'));
        $this->assertNotNull($show->json('data.download_urls.pdf'));
    }
}
