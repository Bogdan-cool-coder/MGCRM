<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/activities/bulk — create one task on several deals (board toolbar).
 */
class ActivityBulkTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_bulk_creates_one_task_per_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $a = $this->dealFor($director, $pipeline);
        $b = $this->dealFor($director, $pipeline);

        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/activities/bulk', [
            'deal_ids' => [$a->id, $b->id],
            'type' => ActivityType::Call->value,
            'title' => 'Follow up with client',
            'due_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('activities', [
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $a->id,
            'kind' => ActivityType::Call->value,
            'title' => 'Follow up with client',
        ]);
        $this->assertDatabaseHas('activities', [
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $b->id,
            'title' => 'Follow up with client',
        ]);
    }

    public function test_bulk_assigns_responsible(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $assignee = User::factory()->create(['role' => Role::Manager]);
        $a = $this->dealFor($director, $pipeline);

        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/activities/bulk', [
            'deal_ids' => [$a->id],
            'type' => ActivityType::Task->value,
            'title' => 'Assigned task',
            'responsible_id' => $assignee->id,
        ])->assertOk();

        $this->assertDatabaseHas('activities', [
            'target_id' => $a->id,
            'responsible_id' => $assignee->id,
            'created_by_id' => $director->id,
        ]);
    }

    public function test_bulk_forbidden_when_a_deal_is_foreign(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = $this->manager();
        $other = $this->manager();
        $mine = $this->dealFor($owner, $pipeline);
        $theirs = $this->dealFor($other, $pipeline);

        Sanctum::actingAs($owner, ['*']);

        $this->postJson('/api/activities/bulk', [
            'deal_ids' => [$mine->id, $theirs->id],
            'type' => ActivityType::Task->value,
            'title' => 'Should not create',
        ])->assertForbidden();

        // All-or-nothing: nothing created on my own deal either.
        $this->assertDatabaseMissing('activities', ['title' => 'Should not create']);
    }

    public function test_bulk_validates_type(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = $this->director();
        $a = $this->dealFor($director, $pipeline);

        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/activities/bulk', [
            'deal_ids' => [$a->id],
            'type' => 'invalid_kind',
            'title' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrorFor('type');
    }
}
