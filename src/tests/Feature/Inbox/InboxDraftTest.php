<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inbox drafts (СРЕЗ B, contract §4.6): per-author CRUD, the one per-user Inbox
 * entity. viewAny/create only need inbox.manage; view/update/delete additionally
 * require ownership (no admin bypass — see InboxDraftPolicy).
 */
class InboxDraftTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_list_show_update_delete_own_draft(): void
    {
        $admin = $this->actAsAdmin();

        $created = $this->postJson('/api/inbox/drafts', [
            'subject' => 'Re: pricing',
            'body' => 'Draft body',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $admin->id)
            ->assertJsonPath('data.subject', 'Re: pricing');

        $draftId = $created->json('data.id');

        $this->getJson('/api/inbox/drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draftId);

        $this->getJson("/api/inbox/drafts/{$draftId}")
            ->assertOk()
            ->assertJsonPath('data.subject', 'Re: pricing');

        $this->patchJson("/api/inbox/drafts/{$draftId}", ['subject' => 'Updated subject'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Updated subject');

        $this->deleteJson("/api/inbox/drafts/{$draftId}")->assertStatus(204);

        $this->assertSame(0, InboxDraft::query()->count());
    }

    public function test_draft_create_links_to_related_message(): void
    {
        $this->actAsAdmin();
        $message = InboundMessage::factory()->create();

        $response = $this->postJson('/api/inbox/drafts', [
            'related_message_id' => $message->id,
            'subject' => 'Re: original',
        ])->assertCreated();

        $this->assertSame($message->id, $response->json('data.related_message_id'));
    }

    public function test_draft_create_rejects_invalid_related_message_id(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/inbox/drafts', ['related_message_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('related_message_id');
    }

    public function test_list_only_returns_the_callers_own_drafts(): void
    {
        $me = $this->actAsAdmin();
        $other = User::factory()->create(['role' => Role::Admin]);

        InboxDraft::factory()->for($me, 'user')->create(['subject' => 'mine']);
        InboxDraft::factory()->for($other, 'user')->create(['subject' => 'theirs']);

        $this->getJson('/api/inbox/drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'mine');
    }

    public function test_viewing_updating_deleting_another_users_draft_is_forbidden(): void
    {
        $this->actAsAdmin();
        $other = User::factory()->create(['role' => Role::Admin]);
        $theirDraft = InboxDraft::factory()->for($other, 'user')->create(['subject' => 'theirs-untouched']);

        $this->getJson("/api/inbox/drafts/{$theirDraft->id}")->assertForbidden();
        $this->patchJson("/api/inbox/drafts/{$theirDraft->id}", ['subject' => 'hijack'])->assertForbidden();
        $this->deleteJson("/api/inbox/drafts/{$theirDraft->id}")->assertForbidden();

        $this->assertSame('theirs-untouched', $theirDraft->fresh()->subject);
        $this->assertNotNull(InboxDraft::find($theirDraft->id));
    }

    public function test_manager_without_inbox_manage_is_forbidden_on_all_draft_actions(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/inbox/drafts')->assertForbidden();
        $this->postJson('/api/inbox/drafts', ['subject' => 'x'])->assertForbidden();
    }

    public function test_deleting_related_message_nulls_the_draft_link_but_keeps_the_draft(): void
    {
        $admin = $this->actAsAdmin();
        $message = InboundMessage::factory()->create();
        $draft = InboxDraft::factory()->for($admin, 'user')->create(['related_message_id' => $message->id]);

        $message->delete();

        $this->assertNotNull($draft->fresh());
        $this->assertNull($draft->fresh()->related_message_id);
    }

    public function test_update_rejects_invalid_related_message_id(): void
    {
        $admin = $this->actAsAdmin();
        $draft = InboxDraft::factory()->for($admin, 'user')->create();

        $this->patchJson("/api/inbox/drafts/{$draft->id}", ['related_message_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('related_message_id');
    }
}
