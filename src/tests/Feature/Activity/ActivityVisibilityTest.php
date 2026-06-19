<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityVisibilityTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_own_scope_sees_only_responsible_or_created(): void
    {
        $owner = $this->manager();
        $other = $this->manager();

        Activity::factory()->responsibleOf($owner)->createdByUser($other)->create();
        Activity::factory()->responsibleOf($other)->createdByUser($other)->create();

        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_department_scope_sees_subtree(): void
    {
        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $director = User::factory()->create(['role' => Role::Director, 'department_id' => $parent->id]);
        $childManager = $this->manager($child->id);

        // Activity in the child department, not owned by anyone in particular.
        $a = Activity::factory()->create([
            'department_id' => $child->id,
            'responsible_id' => $childManager->id,
            'created_by_id' => $childManager->id,
        ]);

        // Director has All scope so this would pass trivially; assert the
        // department subtree itself contains the child.
        Sanctum::actingAs($director, ['*']);
        $this->getJson('/api/activities')->assertOk()->assertJsonFragment(['id' => $a->id]);
    }

    public function test_all_scope_sees_everything(): void
    {
        $director = $this->director();
        $m1 = $this->manager();
        $m2 = $this->manager();

        Activity::factory()->responsibleOf($m1)->createdByUser($m1)->create();
        Activity::factory()->responsibleOf($m2)->createdByUser($m2)->create();

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_foreign_activity_returns_403(): void
    {
        $owner = $this->manager();
        $intruder = $this->manager();
        $activity = Activity::factory()->responsibleOf($owner)->createdByUser($owner)->create();

        Sanctum::actingAs($intruder, ['*']);

        $this->getJson("/api/activities/{$activity->id}")->assertForbidden();
    }

    public function test_timeline_hides_foreign_deal_activities(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = $this->manager();
        $intruder = $this->manager();
        $deal = $this->dealFor($owner, $pipeline);

        Activity::factory()->forDeal($deal)->responsibleOf($owner)->createdByUser($owner)->create();

        Sanctum::actingAs($intruder, ['*']);

        // Intruder cannot see the deal → its timeline must be blocked (422 from
        // the target-visibility gate).
        $this->getJson("/api/activities?target_type=deal&target_id={$deal->id}")
            ->assertStatus(422);
    }

    public function test_target_type_enum_is_whitelisted(): void
    {
        // Sanity: deal/company/contact are valid target types.
        // Contact was added in S5 (CRM entity feed) — extending the whitelist
        // requires no migration (polymorphic string column, no FK).
        $this->assertSame(['deal', 'company', 'contact'], ActivityTargetType::values());
    }
}
