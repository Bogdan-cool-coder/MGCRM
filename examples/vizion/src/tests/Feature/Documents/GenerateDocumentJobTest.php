<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Contracts\DocumentObjectDataResolver;
use App\Jobs\GenerateDocumentJob;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documents\GotenbergClient;
use App\Services\MacroData\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Documents\Concerns\MakesDocxFixture;
use Tests\TestCase;

/**
 * Integration test for the docx render branch of GenerateDocumentJob.
 *
 * Exercises handle() end-to-end with a real DocxTemplateService filling a real
 * .docx fixture; only the MacroData connection, the object resolver and the
 * Gotenberg client are stubbed (they touch external systems). Covers the happy
 * path (filled docx + pdf persisted, status=done) and the M5-review fix: a
 * failed read of the filled docx must error the job rather than silently store
 * a 0-byte file with status=done.
 */
class GenerateDocumentJobTest extends TestCase
{
    use MakesDocxFixture;
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 'A',
            'macrodata_host' => '127.0.0.1',
            'macrodata_port' => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url' => 'https://crm.test',
        ]);
    }

    /**
     * Stub the MacroData connection (no real DB) and the object resolver so the
     * job's data-assembly runs without external systems.
     */
    private function bindConnectionAndResolver(array $objectData = []): void
    {
        $connection = Mockery::mock(ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();
        $this->app->instance(ConnectionService::class, $connection);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($objectData);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);
    }

    /** @test */
    public function test_docx_branch_fills_template_and_persists_docx_and_pdf(): void
    {
        Storage::fake('documents');
        $this->bindConnectionAndResolver(['complex_name' => 'ЖК Тест']);

        // Real Gotenberg would convert the filled docx; stub it to fake PDF bytes.
        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldReceive('officeToPdf')->once()->andReturn('%PDF-1.4 fake');
        $this->app->instance(GotenbergClient::class, $gotenberg);

        $company = $this->makeCompany();
        $user = User::factory()->create(['company_id' => $company->id, 'active_company_id' => $company->id]);

        // Upload a real .docx fixture carrying a ${complex_name} placeholder onto
        // the faked disk; source_path points the job at it.
        $sourcePath = 'document-templates/1/template.docx';
        Storage::disk('documents')->put($sourcePath, $this->makeDocxFixtureBytes(['ЖК: ${complex_name}']));

        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'type' => 'docx',
            'source_path' => $sourcePath,
            'config' => [],
        ]);

        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'params' => ['estate_sell_id' => 1, 'locale' => 'ru'],
            'status' => GeneratedDocument::STATUS_PENDING,
        ]);

        (new GenerateDocumentJob($generated->id))->handle(
            app(ConnectionService::class),
            app(DocumentObjectDataResolver::class),
            app(\App\Services\Documents\HtmlDocumentService::class),
            app(GotenbergClient::class),
            app(\App\Services\Documents\DocxTemplateService::class),
            app(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $generated->refresh();
        $this->assertSame(GeneratedDocument::STATUS_DONE, $generated->status);
        $this->assertNull($generated->error);

        $docxPath = "documents/{$generated->id}/document.docx";
        $pdfPath = "documents/{$generated->id}/document.pdf";

        Storage::disk('documents')->assertExists($docxPath);
        Storage::disk('documents')->assertExists($pdfPath);
        $this->assertSame($docxPath, $generated->docx_path);
        $this->assertSame($pdfPath, $generated->pdf_path);

        // The filled docx is a real, non-empty document with the substituted value.
        $bytes = Storage::disk('documents')->get($docxPath);
        $this->assertGreaterThan(0, strlen((string) $bytes));

        $tmp = tempnam(sys_get_temp_dir(), 'vizion_docx_assert_').'.docx';
        file_put_contents($tmp, $bytes);
        $this->assertStringContainsString('ЖК Тест', $this->readDocxText($tmp));
        @unlink($tmp);
    }

    /** @test */
    public function test_docx_branch_errors_when_source_file_missing_on_disk(): void
    {
        Storage::fake('documents');
        $this->bindConnectionAndResolver();

        // Gotenberg must never be reached — the missing source aborts first.
        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldNotReceive('officeToPdf');
        $this->app->instance(GotenbergClient::class, $gotenberg);

        $company = $this->makeCompany();
        $user = User::factory()->create(['company_id' => $company->id, 'active_company_id' => $company->id]);

        // source_path set but the file does not exist on the faked disk.
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'type' => 'docx',
            'source_path' => 'document-templates/1/missing.docx',
            'config' => [],
        ]);

        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'params' => ['estate_sell_id' => 1],
            'status' => GeneratedDocument::STATUS_PENDING,
        ]);

        (new GenerateDocumentJob($generated->id))->handle(
            app(ConnectionService::class),
            app(DocumentObjectDataResolver::class),
            app(\App\Services\Documents\HtmlDocumentService::class),
            app(GotenbergClient::class),
            app(\App\Services\Documents\DocxTemplateService::class),
            app(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $generated->refresh();
        $this->assertSame(GeneratedDocument::STATUS_ERROR, $generated->status);
        $this->assertNotNull($generated->error);
        $this->assertNull($generated->docx_path);
        $this->assertNull($generated->pdf_path);
    }
}
