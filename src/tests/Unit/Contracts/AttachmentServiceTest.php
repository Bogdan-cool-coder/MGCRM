<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Services\AttachmentService;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttachmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->service = app(AttachmentService::class);
    }

    public function test_has_signed_scan_returns_false_when_no_attachments(): void
    {
        $doc = Document::factory()->draft()->create();

        $this->assertFalse($this->service->hasSignedScan($doc));
    }

    public function test_has_signed_scan_returns_true_when_signed_scan_exists(): void
    {
        $doc = Document::factory()->approved()->create();

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        $this->assertTrue($this->service->hasSignedScan($doc));
    }

    public function test_has_signed_scan_returns_false_when_only_other_kinds_exist(): void
    {
        $doc = Document::factory()->draft()->create();

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::Payment->value,
        ]);

        $this->assertFalse($this->service->hasSignedScan($doc));
    }

    public function test_upload_stores_file_on_documents_disk(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();
        $file = UploadedFile::fake()->create('scan.pdf', 512, 'application/pdf');

        $attachment = $this->service->upload($doc, $file, AttachmentKind::SignedScan, $user);

        Storage::disk('documents')->assertExists($attachment->path);
        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id]);
    }

    public function test_upload_path_format_is_correct(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $attachment = $this->service->upload($doc, $file, AttachmentKind::Other, $user);

        // Path: attachments/{doc_id}/other_{uuid8}.pdf
        $this->assertMatchesRegularExpression(
            '#^attachments/\d+/other_[a-f0-9\-]{8,}\.pdf$#',
            $attachment->path,
        );
    }

    public function test_upload_throws_when_archived(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
        ]);
        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

        $this->service->upload($doc, $file, AttachmentKind::SignedScan, $user);
    }

    public function test_delete_removes_file_from_disk(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->draft()->create();
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $attachment = $this->service->upload($doc, $file, AttachmentKind::Other, $user);
        Storage::disk('documents')->assertExists($attachment->path);

        $this->service->delete($doc, $attachment);

        Storage::disk('documents')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
    }

    public function test_delete_throws_when_document_is_signed(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
        ]);
        $attachment = DocumentAttachment::factory()->create(['document_id' => $doc->id]);

        $this->service->delete($doc, $attachment);
    }
}
