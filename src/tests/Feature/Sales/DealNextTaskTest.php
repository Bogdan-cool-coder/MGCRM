<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Activity\ActivityTestHelpers;
use Tests\TestCase;

/**
 * DealPage 2.0 v2 §8 backend deps: next_task on the deal detail DTO (v2-B1) and
 * warn_days/danger_days on the stage DTO (v2-B2).
 */
class DealNextTaskTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_deal_show_exposes_next_task_chip(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
            'stage_changed_at' => now()->subDays(3),
        ]);
        $task = Activity::factory()->call()->forDeal($deal)
            ->create(['due_at' => now()->addDay(), 'title' => 'Перезвонить ЛПР']);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.days_in_stage', 3)
            ->assertJsonPath('data.next_task.id', $task->id)
            ->assertJsonPath('data.next_task.type', ActivityType::Call->value)
            ->assertJsonPath('data.next_task.title', 'Перезвонить ЛПР')
            ->assertJsonPath('data.next_task.is_overdue', false);
    }

    public function test_deal_show_next_task_null_without_open_task(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Director]);

        $deal = Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stage($pipeline, 'new')->id,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.next_task', null);
    }

    public function test_stage_editor_persists_and_exposes_rotting_thresholds(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->seedSalesPipeline();
        $stage = $this->stage($pipeline, 'new');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'warn_days' => 7,
            'danger_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('data.warn_days', 7)
            ->assertJsonPath('data.danger_days', 14);

        $this->assertDatabaseHas('pipeline_stages', [
            'id' => $stage->id,
            'warn_days' => 7,
            'danger_days' => 14,
        ]);
    }

    public function test_stage_editor_rejects_negative_rotting_threshold(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = $this->seedSalesPipeline();
        $stage = $this->stage($pipeline, 'new');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/pipelines/{$pipeline->id}/stages/{$stage->id}", [
            'warn_days' => -1,
        ])->assertStatus(422)->assertJsonValidationErrorFor('warn_days');
    }
}
