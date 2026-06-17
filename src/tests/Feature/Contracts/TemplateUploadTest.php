<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Template;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Jobs\Contracts\CheckTemplateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Feature tests for POST /api/templates/{template}/upload
 *
 * All tests use:
 *   - Bus::fake()      — no real job dispatch
 *   - Storage fake for 'documents' disk
 *   - SQLite :memory: via RefreshDatabase
 *   - Programmatically-created docx fixtures (no binaries committed)
 */
class TemplateUploadTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    private User $lawyer;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->template = Template::factory()->docx()->create();
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->manager = User::factory()->create(['role' => Role::Manager]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build an UploadedFile containing a minimal docx (programmatic, no binary fixture).
     */
    private function makeDocxFile(string $name = 'contract.docx', string $text = 'Предмет договора'): UploadedFile
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText($text);

        $tmpFile = tempnam(sys_get_temp_dir(), 'phpword_upload_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpFile);

        return new UploadedFile($tmpFile, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_lawyer_can_upload_docx_and_version_created(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $response = $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile(),
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'id',
                'template_id',
                'version_number',
                'docx_path',
                'ai_check_status',
            ]]);

        $this->assertNotNull($response->json('data.docx_path'));
        $this->assertEquals($this->template->id, $response->json('data.template_id'));
    }

    public function test_upload_dispatches_check_job(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile(),
        ])->assertStatus(201);

        Bus::assertDispatched(CheckTemplateJob::class);
    }

    public function test_upload_version_status_is_pending(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $response = $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile(),
        ])->assertStatus(201);

        $this->assertEquals(AiCheckStatus::Pending->value, $response->json('data.ai_check_status'));
    }

    public function test_manager_cannot_upload(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->manager, ['*']);

        $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile(),
        ])->assertStatus(403);
    }

    public function test_upload_validates_file_type(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $pdfFile = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

        $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $pdfFile,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_validates_file_size(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        // UploadedFile::fake()->create() size param is in KB; 25 MB = 25600 KB.
        $bigFile = UploadedFile::fake()->create('huge.docx', 25600, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $bigFile,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_increments_version_number(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $first = $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile('v1.docx'),
        ])->assertStatus(201);

        $second = $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile('v2.docx'),
        ])->assertStatus(201);

        $this->assertEquals(1, $first->json('data.version_number'));
        $this->assertEquals(2, $second->json('data.version_number'));
    }

    public function test_admin_can_upload_docx(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/templates/{$this->template->id}/upload", [
            'file' => $this->makeDocxFile(),
        ])->assertStatus(201);
    }
}
