<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRemarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_remark_for_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $admin->id]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/remarks", [
            'text' => 'Please fix the sublicensee name.',
        ])->assertCreated();

        $this->assertSame('Please fix the sublicensee name.', $response->json('data.text'));
        $this->assertDatabaseHas('document_remarks', [
            'document_id' => $doc->id,
            'text' => 'Please fix the sublicensee name.',
        ]);
    }

    public function test_lawyer_can_create_remark_for_document(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $doc = Document::factory()->draft()->create();
        Sanctum::actingAs($lawyer, ['*']);

        $this->postJson("/api/documents/{$doc->id}/remarks", [
            'text' => 'Review the payment schedule.',
        ])->assertCreated();
    }

    public function test_author_cannot_create_remark_directly(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/remarks", [
            'text' => 'I want to add a remark.',
        ])->assertForbidden();
    }

    public function test_can_list_remarks_for_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $admin->id]);

        DocumentRemark::factory()->count(3)->create([
            'document_id' => $doc->id,
            'attempt' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/documents/{$doc->id}/remarks")
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_list_remarks_filters_by_attempt(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $admin->id]);

        DocumentRemark::factory()->count(2)->create([
            'document_id' => $doc->id,
            'attempt' => 1,
        ]);
        DocumentRemark::factory()->count(3)->create([
            'document_id' => $doc->id,
            'attempt' => 2,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/documents/{$doc->id}/remarks?attempt=2")
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_author_can_toggle_resolve_remark(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);
        Sanctum::actingAs($author, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();

        $this->assertTrue($response->json('data.is_resolved'));
    }

    public function test_admin_can_toggle_resolve_remark(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->draft()->create();
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();

        $this->assertTrue($response->json('data.is_resolved'));
    }

    public function test_lawyer_can_toggle_resolve_remark(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $doc = Document::factory()->draft()->create();
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);
        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();

        $this->assertTrue($response->json('data.is_resolved'));
    }

    public function test_other_user_cannot_resolve_others_document_remark(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $owner->id]);
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
        ]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertForbidden();
    }

    public function test_resolve_sets_resolved_at_and_resolved_by(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => false,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
        ]);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();

        $remark->refresh();
        $this->assertTrue($remark->is_resolved);
        $this->assertNotNull($remark->resolved_at);
        $this->assertSame($author->id, $remark->resolved_by_user_id);
    }

    public function test_resolve_again_clears_resolved_fields(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        $remark = DocumentRemark::factory()->create([
            'document_id' => $doc->id,
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by_user_id' => $author->id,
        ]);
        Sanctum::actingAs($author, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/remarks/{$remark->id}/resolve")
            ->assertOk();

        $this->assertFalse($response->json('data.is_resolved'));

        $remark->refresh();
        $this->assertFalse($remark->is_resolved);
        $this->assertNull($remark->resolved_at);
        $this->assertNull($remark->resolved_by_user_id);
    }
}
