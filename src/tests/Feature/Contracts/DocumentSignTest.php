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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentSignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_can_sign_approved_document_with_signed_scan(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->approved()->create(['author_user_id' => $author->id]);

        // Attach a signed_scan
        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        Sanctum::actingAs($author, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/sign")
            ->assertOk();

        $this->assertSame(ContractStatus::Signed->value, $response->json('data.status'));
    }

    public function test_cannot_sign_without_signed_scan(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->approved()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        // No attachments at all
        $this->postJson("/api/documents/{$doc->id}/sign")
            ->assertUnprocessable();
    }

    public function test_cannot_sign_non_approved_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/sign")
            ->assertUnprocessable();
    }

    public function test_sign_sets_signed_at(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->approved()->create(['author_user_id' => $author->id]);

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/sign")->assertOk();

        $doc->refresh();
        $this->assertNotNull($doc->signed_at);
        $this->assertSame(ContractStatus::Signed, $doc->status);
    }

    public function test_admin_can_unsign_signed_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
            'author_user_id' => $admin->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/unsign")
            ->assertOk();

        $this->assertSame(ContractStatus::Approved->value, $response->json('data.status'));
    }

    public function test_lawyer_can_unsign_signed_document(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
        ]);
        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/unsign")
            ->assertOk();

        $this->assertSame(ContractStatus::Approved->value, $response->json('data.status'));
    }

    public function test_unsign_clears_signed_at_and_sets_approved(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
            'author_user_id' => $admin->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/unsign")->assertOk();

        $doc->refresh();
        $this->assertSame(ContractStatus::Approved, $doc->status);
        $this->assertNull($doc->signed_at);
    }

    public function test_non_admin_cannot_unsign(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Signed->value,
            'signed_at' => now(),
            'author_user_id' => $manager->id,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/documents/{$doc->id}/unsign")
            ->assertForbidden();
    }
}
