<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_author_can_upload_signed_scan(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->approved()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $file = UploadedFile::fake()->create('scan.pdf', 512, 'application/pdf');

        $response = $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::SignedScan->value,
        ])->assertCreated();

        $this->assertSame(AttachmentKind::SignedScan->value, $response->json('data.kind'));
        $this->assertNotNull($response->json('data.download_url'));
        $this->assertDatabaseHas('document_attachments', [
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);
    }

    public function test_upload_rejects_invalid_mime(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        // Laravel's UploadedFile::fake()->create() with a non-whitelisted extension
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::Other->value,
        ])->assertUnprocessable();
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        // 20 MB — over the 15 MB limit
        $file = UploadedFile::fake()->create('large.pdf', 20 * 1024, 'application/pdf');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::Other->value,
        ])->assertUnprocessable();
    }

    public function test_upload_rejects_invalid_kind(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => 'invalid_kind',
        ])->assertUnprocessable();
    }

    public function test_upload_rejected_for_archived_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
            'author_user_id' => $admin->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::Other->value,
        ])->assertUnprocessable();
    }

    // BUG-ATTACH-1: signed_scan arrives during review / rework — uploads must be allowed.

    public function test_upload_allowed_when_status_is_in_review(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->inReview()->create(['author_user_id' => $admin->id]);
        Sanctum::actingAs($admin, ['*']);

        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::SignedScan->value,
        ])->assertCreated();
    }

    public function test_upload_allowed_when_status_is_needs_rework(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::NeedsRework->value,
            'author_user_id' => $admin->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

        $this->postJson("/api/documents/{$doc->id}/attachments", [
            'file' => $file,
            'kind' => AttachmentKind::SignedScan->value,
        ])->assertCreated();
    }

    public function test_can_list_attachments(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);

        DocumentAttachment::factory()->count(3)->create(['document_id' => $doc->id]);
        Sanctum::actingAs($author, ['*']);

        $response = $this->getJson("/api/documents/{$doc->id}/attachments")
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_author_can_delete_attachment_if_not_signed(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $attachment = DocumentAttachment::factory()->create(['document_id' => $doc->id]);
        Sanctum::actingAs($author, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}/attachments/{$attachment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
    }

    public function test_cannot_delete_attachment_of_signed_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
            'author_user_id' => $author->id,
        ]);
        $attachment = DocumentAttachment::factory()->create(['document_id' => $doc->id]);
        Sanctum::actingAs($author, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}/attachments/{$attachment->id}")
            ->assertUnprocessable();
    }

    public function test_admin_cannot_delete_attachment_of_signed_document(): void
    {
        // Per plan Q3: guard applies to everyone including admin (audit integrity)
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
            'author_user_id' => $admin->id,
        ]);
        $attachment = DocumentAttachment::factory()->create(['document_id' => $doc->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/documents/{$doc->id}/attachments/{$attachment->id}")
            ->assertUnprocessable();
    }

    public function test_download_attachment_returns_file_stream(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);

        // Put a fake file into the fake disk
        Storage::disk('documents')->put('attachments/1/other_testfile.pdf', 'PDF content');

        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'path' => 'attachments/1/other_testfile.pdf',
            'original_name' => 'test.pdf',
            'content_type' => 'application/pdf',
        ]);

        Sanctum::actingAs($author, ['*']);

        $this->get("/api/documents/{$doc->id}/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    public function test_download_nonexistent_file_returns_404(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);

        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'path' => 'attachments/999/nonexistent.pdf',
            'original_name' => 'missing.pdf',
        ]);

        Sanctum::actingAs($author, ['*']);

        $this->get("/api/documents/{$doc->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }
}
