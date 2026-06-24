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

    public function test_reassigning_responsible_resyncs_department_id(): void
    {
        // Audit MINOR-10 (data-integrity half): handing a task to a user in a
        // different department must re-stamp the denormalised department_id from
        // the new responsible so department-scoped visibility follows the owner.
        $deptA = \App\Domain\Org\Models\Department::factory()->create();
        $deptB = \App\Domain\Org\Models\Department::factory()->create();

        $director = $this->director(); // All-scope → may reassign freely
        $newResponsible = $this->manager($deptB->id);

        $activity = Activity::factory()
            ->responsibleOf($director)
            ->createdByUser($director)
            ->create(['department_id' => $deptA->id]);
        Sanctum::actingAs($director, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['responsible_id' => $newResponsible->id])
            ->assertOk()
            ->assertJsonPath('data.responsible_id', $newResponsible->id)
            ->assertJsonPath('data.department_id', $deptB->id);

        $this->assertSame($deptB->id, $activity->fresh()->department_id);
    }

    public function test_explicit_department_id_wins_over_reassign_resync(): void
    {
        // An explicit department_id passed to the service is authoritative — the
        // responsible re-sync never overrides a caller-set department. (department_id
        // is not part of the public PATCH contract, so this exercises the service
        // directly to cover the guard for internal callers.)
        $deptB = \App\Domain\Org\Models\Department::factory()->create();
        $deptC = \App\Domain\Org\Models\Department::factory()->create();

        $director = $this->director();
        $newResponsible = $this->manager($deptB->id);

        $activity = Activity::factory()
            ->responsibleOf($director)
            ->createdByUser($director)
            ->create(['department_id' => null]);

        $service = app(\App\Domain\Activity\Services\ActivityService::class);
        $updated = $service->update($activity, [
            'responsible_id' => $newResponsible->id,
            'department_id' => $deptC->id,
        ]);

        $this->assertSame($deptC->id, $updated->department_id);
    }

    public function test_reassign_without_department_falls_back_when_responsible_has_none(): void
    {
        // Reassigning to a user with no department leaves the existing department_id
        // untouched (no owner department to derive from).
        $deptA = \App\Domain\Org\Models\Department::factory()->create();
        $director = $this->director();
        $deptlessResponsible = $this->manager(null);

        $activity = Activity::factory()
            ->responsibleOf($director)
            ->createdByUser($director)
            ->create(['department_id' => $deptA->id]);

        $service = app(\App\Domain\Activity\Services\ActivityService::class);
        $updated = $service->update($activity, ['responsible_id' => $deptlessResponsible->id]);

        $this->assertSame($deptA->id, $updated->department_id);
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
