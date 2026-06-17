<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_archive_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/archive")
            ->assertOk();

        $this->assertNotNull($response->json('data.archived_at'));
    }

    public function test_cannot_archive_in_review_document(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->inReview()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/archive")
            ->assertUnprocessable();
    }

    public function test_archive_sets_archived_at_but_keeps_status(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->draft()->create(['author_user_id' => $author->id]);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/documents/{$doc->id}/archive")->assertOk();

        $doc->refresh();
        $this->assertNotNull($doc->archived_at);
        $this->assertSame(ContractStatus::Draft, $doc->status); // status unchanged
    }

    public function test_archived_document_hidden_from_default_list(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
            'author_user_id' => $user->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_archived_document_visible_with_archived_filter(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Document::factory()->draft()->create(['author_user_id' => $user->id]);
        Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
            'author_user_id' => $user->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/documents?archived=1')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_can_unarchive_document(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
            'author_user_id' => $admin->id,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/unarchive")
            ->assertOk();

        $this->assertNull($response->json('data.archived_at'));
    }

    public function test_lawyer_can_unarchive_document(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
        ]);
        Sanctum::actingAs($lawyer, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/unarchive")
            ->assertOk();

        $this->assertNull($response->json('data.archived_at'));
    }

    public function test_non_admin_cannot_unarchive(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $doc = Document::factory()->create([
            'status' => ContractStatus::Draft->value,
            'archived_at' => now(),
            'author_user_id' => $manager->id,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/documents/{$doc->id}/unarchive")
            ->assertForbidden();
    }
}
