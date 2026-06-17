<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityCrudTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_user_can_create_activity_on_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Call->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Call the client',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'call')
            ->assertJsonPath('data.target_type', 'deal')
            ->assertJsonPath('data.target_id', $deal->id)
            ->assertJsonPath('data.created_by_id', $manager->id);
    }

    public function test_create_activity_response_serialises_status_new(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        // The POST response must carry status: 'new' (not null) right after create,
        // otherwise the UI renders the raw i18n key activity.statuses.null until a
        // reload (BUG-3).
        $this->postJson('/api/activities', [
            'kind' => ActivityType::Call->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Call the client',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'new');
    }

    public function test_user_can_create_activity_on_company(): void
    {
        $this->seedSalesPipeline();
        $manager = $this->manager();
        $company = $this->companyFor($manager);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Task->value,
            'target_type' => 'company',
            'target_id' => $company->id,
            'title' => 'Prepare proposal',
        ])->assertCreated()
            ->assertJsonPath('data.target_type', 'company')
            ->assertJsonPath('data.target_id', $company->id);
    }

    public function test_user_can_create_standalone_activity_with_responsible_forced_to_self(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Task->value,
            'title' => 'Personal task',
        ])->assertCreated()
            ->assertJsonPath('data.target_type', null)
            ->assertJsonPath('data.responsible_id', $manager->id);
    }

    public function test_create_activity_blocked_by_stage_task_types(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);

        // Restrict the deal's stage to calls only.
        PipelineStage::whereKey($deal->stage_id)->update(['task_types' => ['call']]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Meeting->value, // not in whitelist
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Blocked meeting',
        ])->assertStatus(422)->assertJsonValidationErrorFor('kind');
    }

    public function test_create_activity_allowed_when_task_types_empty(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);
        // Default seeded task_types are empty → all kinds allowed.
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Meeting->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Allowed meeting',
        ])->assertCreated();
    }

    public function test_create_activity_requires_target_id_with_target_type(): void
    {
        $this->seedSalesPipeline();
        $manager = $this->manager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => ActivityType::Task->value,
            'target_type' => 'deal',
            'title' => 'No target id',
        ])->assertStatus(422)->assertJsonValidationErrorFor('target_id');
    }

    public function test_create_activity_rejects_invisible_target(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = $this->manager();
        $intruder = $this->manager();
        $deal = $this->dealFor($owner, $pipeline);
        Sanctum::actingAs($intruder, ['*']);

        // The intruder cannot see the deal → IDOR write must be blocked.
        $this->postJson('/api/activities', [
            'kind' => ActivityType::Call->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'IDOR attempt',
        ])->assertStatus(422)->assertJsonValidationErrorFor('target_id');
    }

    public function test_update_activity_partial(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_update_activity_rejects_status_field(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['status' => 'done'])
            ->assertStatus(422)->assertJsonValidationErrorFor('status');
    }

    public function test_update_activity_rejects_target_change(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['target_type' => 'deal', 'target_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrorFor('target_type');
    }

    public function test_destroy_activity_returns_204(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/activities/{$activity->id}")->assertNoContent();
        $this->assertDatabaseMissing('activities', ['id' => $activity->id]);
    }
}
