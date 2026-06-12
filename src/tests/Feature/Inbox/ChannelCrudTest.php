<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChannelCrudTest extends TestCase
{
    use InboxTestHelpers;
    use RefreshDatabase;

    public function test_admin_can_create_channel_returns_full_token_once(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $response = $this->postJson('/api/channels', [
            'name' => 'Site form',
            'kind' => 'web_form',
        ])->assertCreated();

        // Full token returned exactly once on create.
        $token = $response->json('data.secret_token');
        $this->assertNotNull($token);
        $this->assertGreaterThanOrEqual(40, strlen($token));
        // Preview is masked.
        $this->assertStringStartsWith('****', $response->json('data.secret_token_preview'));
        // default_lead_source derived from kind.
        $this->assertSame('form', $response->json('data.default_lead_source'));
    }

    public function test_list_and_get_mask_token(): void
    {
        $channel = Channel::factory()->create(['secret_token' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCD']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $list = $this->getJson('/api/channels')->assertOk();
        $this->assertStringStartsWith('****', $list->json('data.0.secret_token_preview'));
        $this->assertArrayNotHasKey('secret_token', $list->json('data.0'));

        $get = $this->getJson("/api/channels/{$channel->id}")->assertOk();
        $this->assertArrayNotHasKey('secret_token', $get->json('data'));
    }

    public function test_manager_cannot_create_channel(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/channels', ['name' => 'x', 'kind' => 'web_form'])
            ->assertForbidden();
    }

    public function test_reveal_token_admin_returns_full_manager_forbidden(): void
    {
        $channel = Channel::factory()->create();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
        $this->getJson("/api/channels/{$channel->id}/reveal-token")
            ->assertOk()
            ->assertJsonPath('data.secret_token', $channel->secret_token);

        $this->flushAuth();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);
        $this->getJson("/api/channels/{$channel->id}/reveal-token")
            ->assertForbidden();
    }

    public function test_regenerate_token_changes_token(): void
    {
        $channel = Channel::factory()->create();
        $old = $channel->secret_token;
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $new = $this->postJson("/api/channels/{$channel->id}/regenerate-token")
            ->assertOk()
            ->json('data.secret_token');

        $this->assertNotSame($old, $new);
        $this->assertDatabaseHas('channels', ['id' => $channel->id, 'secret_token' => $new]);
    }

    public function test_delete_channel_with_forms_returns_409_without_force(): void
    {
        $channel = Channel::factory()->create();
        Form::factory()->create(['channel_id' => $channel->id]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/channels/{$channel->id}")->assertStatus(409);
        $this->assertDatabaseHas('channels', ['id' => $channel->id]);
    }

    public function test_delete_channel_with_force_orphans_forms(): void
    {
        $channel = Channel::factory()->create();
        $form = Form::factory()->create(['channel_id' => $channel->id]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->deleteJson("/api/channels/{$channel->id}?force=1")->assertNoContent();
        $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
        $this->assertDatabaseHas('forms', ['id' => $form->id, 'channel_id' => null]);
    }

    public function test_invalid_kind_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson('/api/channels', ['name' => 'x', 'kind' => 'carrier_pigeon'])
            ->assertStatus(422);
    }

    public function test_stage_not_in_pipeline_rejected(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $newStage = $this->newStageId($pipeline);

        // A stage that belongs to a different pipeline.
        $otherPipeline = Pipeline::factory()->create();
        $otherStage = PipelineStage::factory()->create([
            'pipeline_id' => $otherPipeline->id,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);

        $this->postJson('/api/channels', [
            'name' => 'x',
            'kind' => 'web_form',
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $otherStage->id,
        ])->assertStatus(422);

        // sanity: the matching stage passes.
        $this->postJson('/api/channels', [
            'name' => 'ok',
            'kind' => 'web_form',
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $newStage,
        ])->assertCreated();
    }
}
