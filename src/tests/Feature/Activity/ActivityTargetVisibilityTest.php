<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit B4: acting on an activity (complete / update / delete / reschedule /
 * changeStatus) requires CURRENT visibility of its polymorphic target — not just
 * ownership of the activity row. A manager who is responsible for a task whose
 * parent deal is owned by someone else (and therefore invisible under Own scope)
 * must be forbidden from mutating it, mirroring the create-time target gate.
 */
class ActivityTargetVisibilityTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    /**
     * A task the actor is responsible for, but whose target deal belongs to
     * another manager — so under Own scope the actor cannot view the deal.
     */
    private function taskWithForeignTarget(): array
    {
        $pipeline = $this->seedSalesPipeline();
        $actor = $this->manager();
        $other = $this->manager();
        $foreignDeal = $this->dealFor($other, $pipeline);

        $activity = Activity::factory()->task()->forDeal($foreignDeal)
            ->responsibleOf($actor)->createdByUser($actor)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        return [$actor, $activity, $foreignDeal];
    }

    public function test_cannot_complete_when_target_not_visible(): void
    {
        [$actor, $activity] = $this->taskWithForeignTarget();
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")->assertForbidden();
    }

    public function test_cannot_update_when_target_not_visible(): void
    {
        [$actor, $activity] = $this->taskWithForeignTarget();
        Sanctum::actingAs($actor, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['title' => 'edited'])
            ->assertForbidden();
    }

    public function test_cannot_delete_when_target_not_visible(): void
    {
        [$actor, $activity] = $this->taskWithForeignTarget();
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson("/api/activities/{$activity->id}")->assertForbidden();
    }

    public function test_cannot_reschedule_when_target_not_visible(): void
    {
        [$actor, $activity] = $this->taskWithForeignTarget();
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => '+1d'])
            ->assertForbidden();
    }

    public function test_cannot_change_status_when_target_not_visible(): void
    {
        [$actor, $activity] = $this->taskWithForeignTarget();
        Sanctum::actingAs($actor, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'rejected'])
            ->assertForbidden();
    }

    public function test_owner_of_target_can_still_complete(): void
    {
        // Control: when the actor CAN see the target deal (they own it), the same
        // task is fully actionable.
        $pipeline = $this->seedSalesPipeline();
        $actor = $this->manager();
        $deal = $this->dealFor($actor, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($actor)->createdByUser($actor)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_standalone_task_stays_actionable_by_owner(): void
    {
        // A target-less personal task keeps the ownership-only rule (no target to
        // gate). The responsible user may complete it.
        $actor = $this->manager();
        $activity = Activity::factory()->task()->standalone()
            ->responsibleOf($actor)->createdByUser($actor)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")->assertOk();
    }
}
