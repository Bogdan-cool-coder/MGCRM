<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\TemplateCheckService;
use App\Jobs\Contracts\CheckTemplateJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for CheckTemplateJob.
 *
 * Tests verify status transitions and error handling without real AI/Gotenberg calls.
 */
class CheckTemplateJobTest extends TestCase
{
    use RefreshDatabase;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().'/mgcrm_job_test_'.uniqid();
        mkdir($this->diskRoot, 0755, true);

        config(['filesystems.disks.documents' => [
            'driver' => 'local',
            'root' => $this->diskRoot,
        ]]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map('unlink', glob($this->diskRoot.'/*') ?: []);
        @rmdir($this->diskRoot);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createDocxFixture(string $filename = 'template.docx'): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Предмет договора — тестовый шаблон');

        $tmpFile = tempnam(sys_get_temp_dir(), 'phpword_job_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpFile);
        $contents = file_get_contents($tmpFile);
        unlink($tmpFile);

        file_put_contents($this->diskRoot.'/'.$filename, $contents);

        return $filename;
    }

    private function makeVersion(?string $docxPath = null): TemplateVersion
    {
        return TemplateVersion::factory()->create([
            'ai_check_status' => AiCheckStatus::Pending,
            'docx_path' => $docxPath ?? $this->createDocxFixture(),
        ]);
    }

    private function fakePdf(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 '.str_repeat('A', 1100),
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_job_sets_status_checking_on_start(): void
    {
        $version = $this->makeVersion();

        // Mock TemplateCheckService to capture the intermediate status.
        // We use a spy: replace the service with one that asserts status=checking
        // before returning a result.
        $capturedStatus = null;

        $this->app->bind(TemplateCheckService::class, function () use ($version, &$capturedStatus) {
            return new class($version, $capturedStatus) extends TemplateCheckService
            {
                public function __construct(
                    private TemplateVersion $v,
                    private mixed &$captured,
                ) {
                    // No-op: skip constructor injection in mock
                }

                public function check(TemplateVersion $version): array
                {
                    $this->captured = TemplateVersion::find($this->v->id)?->ai_check_status;

                    return ['remarks' => [], 'pdf_ok' => true];
                }
            };
        });

        (new CheckTemplateJob($version->id))->handle($this->app->make(TemplateCheckService::class));

        $this->assertSame(AiCheckStatus::Checking, $capturedStatus);
    }

    public function test_job_sets_status_checked_on_success(): void
    {
        $version = $this->makeVersion();

        Prism::fake([
            TextResponseFake::make()->withText('```json'."\n".json_encode(['remarks' => []])."\n```"),
        ]);
        $this->fakePdf();

        (new CheckTemplateJob($version->id))->handle($this->app->make(TemplateCheckService::class));

        $version->refresh();
        $this->assertSame(AiCheckStatus::Checked, $version->ai_check_status);
        $this->assertNotNull($version->ai_checked_at);
        $this->assertIsArray($version->ai_remarks);
    }

    public function test_job_sets_status_failed_on_ai_exception(): void
    {
        $version = $this->makeVersion();

        // Prism::fake throws when the driver throws.
        // We bind a mock TemplateCheckService that throws.
        $this->app->bind(TemplateCheckService::class, function () {
            return new class extends TemplateCheckService
            {
                public function __construct()
                {
                    // Skip parent injection
                }

                public function check(TemplateVersion $version): array
                {
                    throw new RuntimeException('Anthropic API unreachable');
                }
            };
        });

        (new CheckTemplateJob($version->id))->handle($this->app->make(TemplateCheckService::class));

        $version->refresh();
        $this->assertSame(AiCheckStatus::Failed, $version->ai_check_status);
        $this->assertNotNull($version->ai_checked_at);
        $this->assertNotNull($version->ai_remarks);

        $types = array_column($version->ai_remarks, 'type');
        $this->assertContains('system_error', $types);
    }

    public function test_job_sets_status_failed_on_gotenberg_exception(): void
    {
        $version = $this->makeVersion();

        $this->app->bind(TemplateCheckService::class, function () {
            return new class extends TemplateCheckService
            {
                public function __construct() {}

                public function check(TemplateVersion $version): array
                {
                    throw new RuntimeException('Gotenberg connection refused');
                }
            };
        });

        (new CheckTemplateJob($version->id))->handle($this->app->make(TemplateCheckService::class));

        $version->refresh();
        $this->assertSame(AiCheckStatus::Failed, $version->ai_check_status);
        $texts = array_column($version->ai_remarks ?? [], 'text');
        $this->assertNotEmpty($texts);
    }

    public function test_job_missing_version_gracefully_fails(): void
    {
        // Version ID that does not exist → findOrFail throws ModelNotFoundException.
        // The job has no try/catch around findOrFail — the exception propagates
        // as expected (the job fails the queue item, not silently ignored).
        // We test that the exception IS thrown (not silently swallowed).
        $this->expectException(ModelNotFoundException::class);

        (new CheckTemplateJob(99999))->handle($this->app->make(TemplateCheckService::class));
    }
}
