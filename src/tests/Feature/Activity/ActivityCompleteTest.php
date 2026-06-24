<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityCompleteTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_responsible_can_complete(): void
    {
        $manager = $this->manager();
        $orderer = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($orderer)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.progress_pct', 100)
            ->assertJsonPath('data.completed_by_id', $manager->id);

        $this->assertNotNull($activity->fresh()->completed_at);
    }

    public function test_creator_can_complete(): void
    {
        $responsible = $this->manager();
        $orderer = $this->manager();
        $activity = Activity::factory()->responsibleOf($responsible)->createdByUser($orderer)->create();
        Sanctum::actingAs($orderer, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.completed_by_id', $orderer->id);
    }

    public function test_outsider_cannot_complete(): void
    {
        $responsible = $this->manager();
        $orderer = $this->manager();
        $outsider = $this->manager();
        $activity = Activity::factory()->responsibleOf($responsible)->createdByUser($orderer)->create();
        Sanctum::actingAs($outsider, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")->assertForbidden();
    }

    public function test_complete_is_idempotent(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_reopen_resets_completion(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.completed_by_id', null);
    }

    public function test_complete_note_returns_422(): void
    {
        $manager = $this->manager();
        $note = Activity::factory()->note()->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$note->id}/complete")->assertStatus(422);
    }

    public function test_status_machine_rejects_illegal_transition(): void
    {
        $manager = $this->manager();
        // status=new → cannot jump straight to done via /status (use /complete).
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::New->value]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'done'])
            ->assertStatus(422)->assertJsonValidationErrorFor('status');
    }

    public function test_status_machine_allows_valid_transition(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::New->value]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_inline_status_done_closes_like_complete(): void
    {
        // Audit MAJOR-3: PATCH /status done must converge with POST /complete —
        // close the task (is_closed) + stamp completion, not just set status.
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.is_closed', true)
            ->assertJsonPath('data.progress_pct', 100)
            ->assertJsonPath('data.completed_by_id', $manager->id);

        $fresh = $activity->fresh();
        $this->assertTrue($fresh->is_closed);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_inline_status_done_records_completion_log_on_target(): void
    {
        // MAJOR-3: the done-branch must also write the completion entity-log, like
        // /complete — otherwise inline-done meetings/tasks never reach the feed.
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'done'])
            ->assertOk();

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'task_completed',
        ]);
    }
}
