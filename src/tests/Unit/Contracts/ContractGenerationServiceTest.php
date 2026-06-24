<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\ContractContextBuilder;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\ContractNumberingService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Contracts\Services\LicensorService;
use App\Domain\Contracts\Services\TemplateService;
use App\Domain\Contracts\Services\YamlTemplateParser;
use App\Domain\Crm\Services\CompanyRequisiteService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Services\Documents\GotenbergClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit tests for ContractGenerationService::generate().
 *
 * Gotenberg is ALWAYS mocked via Http::fake() — no real HTTP calls.
 * PHPWord runs real (in-process) with programmatically-created test fixtures.
 */
class ContractGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Template $masterSkeleton;

    private TemplateVersion $templateVersion;

    private string $docxTemplatePath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->author = User::factory()->create(['role' => Role::Manager]);

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

        // Create master_skeleton template + a docx version.
        $this->masterSkeleton = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);

        // Build minimal docx with template variables and save to the fake disk.
        $this->docxTemplatePath = $this->createMinimalDocxTemplate();

        $this->templateVersion = TemplateVersion::create([
            'template_id' => $this->masterSkeleton->id,
            'version_number' => 1,
            'docx_path' => $this->docxTemplatePath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->author->id,
            'created_at' => now(),
        ]);

        $this->masterSkeleton->update(['current_version_id' => $this->templateVersion->id]);
    }

    // ---- Helpers ----

    /**
     * Create a minimal docx with known template variables, save to fake Storage disk,
     * return the relative disk path.
     */
    private function createMinimalDocxTemplate(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Номер: ${contract.number}');
        $section->addText('Лицензиар: ${licensor.name}');
        $section->addText('Сумма прописью: ${total_in_words}');

        // Items table with cloneRow marker.
        $table = $section->addTable();
        $row = $table->addRow();
        $row->addCell()->addText('${item_name}');
        $row->addCell()->addText('${item_qty}');
        $row->addCell()->addText('${item_total}');

        $tmpPath = sys_get_temp_dir().'/test_template_'.uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = 'templates/master_skeleton/v1_test.docx';
        Storage::disk('documents')->put($diskPath, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $diskPath;
    }

    private function makeService(): ContractGenerationService
    {
        return new ContractGenerationService(
            new TemplateService,
            new ContractContextBuilder(new YamlTemplateParser, new CompanyRequisiteService, new LicensorService),
            new ContractNumberingService,
            app(DocumentService::class),
            new GotenbergClient,
        );
    }

    private function fakePdfResponse(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 minimal pdf content for testing purposes',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    private function makeDocument(array $overrides = []): Document
    {
        return Document::factory()->create(array_merge([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'city_code' => null,
            'number' => null,
            'status' => ContractStatus::Draft,
            'currency' => 'UZS',
            'total' => 12300,
        ], $overrides));
    }

    // ---- Tests ----

    public function test_generates_docx_and_pdf_and_saves_paths(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $result = $service->generate($doc, $this->author->id);

        $this->assertNotNull($result['document']->docx_path);
        $this->assertNotNull($result['document']->pdf_path);
        $this->assertTrue(Storage::disk('documents')->exists($result['document']->docx_path));
        $this->assertTrue(Storage::disk('documents')->exists($result['document']->pdf_path));
    }

    public function test_reserves_number_on_first_generation(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument(['number' => null]);
        $service = $this->makeService();

        $result = $service->generate($doc, $this->author->id);

        $this->assertNotNull($result['document']->number);
        $this->assertMatchesRegularExpression('/^[А-ЯA-Z]+-\d+\/[A-Z]+$/', $result['document']->number);
    }

    public function test_does_not_change_number_on_regeneration(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument(['number' => 'ТАШ-220/UZ', 'city_code' => 'ТАШ']);
        $service = $this->makeService();

        $result1 = $service->generate($doc, $this->author->id);
        $result2 = $service->generate($result1['document'], $this->author->id);

        $this->assertSame('ТАШ-220/UZ', $result2['document']->number);
    }

    public function test_creates_document_revision(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $service->generate($doc, $this->author->id);

        $revision = DocumentRevision::where('document_id', $doc->id)->first();

        $this->assertNotNull($revision);
        $this->assertNotNull($revision->context_snapshot);
        $this->assertSame(1, $revision->version_number);
        // Generation snapshot carries attempt=0; submit will bump it to 1.
        $this->assertSame(0, $revision->attempt);
    }

    public function test_regeneration_increments_revision_version(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument(['number' => 'ТАШ-220/UZ', 'city_code' => 'ТАШ']);
        $service = $this->makeService();

        $r1 = $service->generate($doc, $this->author->id);
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 minimal pdf second gen',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
        $service->generate($r1['document'], $this->author->id);

        $revisions = DocumentRevision::where('document_id', $doc->id)
            ->orderBy('version_number')
            ->get();

        $this->assertCount(2, $revisions);
        $this->assertSame(1, $revisions[0]->version_number);
        $this->assertSame(2, $revisions[1]->version_number);
        // Generation does NOT increment attempt — only submit/resubmit does.
        // Both generation-revisions carry attempt=0 (no approval round yet).
        $this->assertSame(0, $revisions[0]->attempt);
        $this->assertSame(0, $revisions[1]->attempt);
    }

    public function test_422_when_template_has_no_docx(): void
    {
        // Remove the template version.
        $this->masterSkeleton->update(['current_version_id' => null]);
        TemplateVersion::truncate();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $this->expectException(ValidationException::class);

        $service->generate($doc, $this->author->id);
    }

    public function test_503_when_gotenberg_connection_fails(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $this->expectException(HttpException::class);

        try {
            $service->generate($doc, $this->author->id);
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_502_when_gotenberg_returns_error(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response('Conversion failed', 500),
        ]);

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $this->expectException(HttpException::class);

        try {
            $service->generate($doc, $this->author->id);
        } catch (HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_status_stays_draft_after_generation(): void
    {
        $this->fakePdfResponse();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $result = $service->generate($doc, $this->author->id);

        $this->assertSame(ContractStatus::Draft, $result['document']->status);
    }

    public function test_ai_check_warning_when_pdf_ok_false(): void
    {
        $this->templateVersion->update(['pdf_ok' => false]);
        $this->fakePdfResponse();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $result = $service->generate($doc, $this->author->id);

        $this->assertContains('template_not_checked', $result['warnings']);
    }

    public function test_no_warnings_when_pdf_ok_true(): void
    {
        $this->templateVersion->update(['pdf_ok' => true]);
        $this->fakePdfResponse();

        $doc = $this->makeDocument();
        $service = $this->makeService();

        $result = $service->generate($doc, $this->author->id);

        $this->assertEmpty($result['warnings']);
    }

    public function test_422_when_city_missing(): void
    {
        $doc = $this->makeDocument(['city' => '']);
        $service = $this->makeService();

        $this->expectException(ValidationException::class);

        $service->generate($doc, $this->author->id);
    }
}
