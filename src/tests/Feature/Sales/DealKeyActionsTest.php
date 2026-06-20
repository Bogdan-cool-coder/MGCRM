<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal-card header "ключевые действия" — the key_actions block on the DealResource
 * and the manual kp-sent / contract-sent endpoints.
 */
class DealKeyActionsTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function dealFor(User $user, string $stageCode = 'new'): Deal
    {
        $pipeline = $this->seedSalesPipeline();
        $stageId = $this->stageCode($pipeline, $stageCode);

        return Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stageId,
            'max_stage_id' => $stageId,
        ]);
    }

    /**
     * Create a completed deal-targeted activity of a given kind.
     */
    private function completedActivity(Deal $deal, ActivityType $kind, ?\DateTimeInterface $completedAt = null): Activity
    {
        return Activity::factory()->create([
            'kind' => $kind->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'status' => ActivityStatus::Done->value,
            'completed_at' => $completedAt ?? now(),
        ]);
    }

    public function test_show_returns_six_key_actions_in_order(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $types = $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->json('data.key_actions.*.type');

        $this->assertSame(
            ['last_presentation', 'max_stage', 'kp_sent', 'contract_sent', 'last_touch', 'last_event'],
            $types,
        );
    }

    public function test_key_action_dates_are_null_when_nothing_happened(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $actions = collect(
            $this->getJson("/api/deals/{$deal->id}")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        $this->assertNull($actions['last_presentation']['date']);
        $this->assertNull($actions['kp_sent']['date']);
        $this->assertNull($actions['contract_sent']['date']);
        $this->assertNull($actions['last_touch']['date']);
        $this->assertNull($actions['last_event']['date']);
    }

    public function test_last_presentation_at_is_the_latest_completed_presentation(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);

        // An OPEN presentation must NOT count.
        Activity::factory()->create([
            'kind' => ActivityType::Presentation->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'status' => ActivityStatus::New->value,
            'completed_at' => null,
        ]);
        $this->completedActivity($deal, ActivityType::Presentation, now()->subDays(5));
        $latest = $this->completedActivity($deal, ActivityType::Presentation, now()->subDay());

        Sanctum::actingAs($user, ['*']);

        $actions = collect(
            $this->getJson("/api/deals/{$deal->id}")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        $this->assertSame(
            $latest->completed_at->toIso8601String(),
            $actions['last_presentation']['date'],
        );
    }

    public function test_last_touch_counts_call_and_follow_up_but_not_meeting(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);

        // A meeting is an event but NOT a touch.
        $this->completedActivity($deal, ActivityType::Meeting, now()->subHour());
        $call = $this->completedActivity($deal, ActivityType::Call, now()->subDays(2));

        Sanctum::actingAs($user, ['*']);

        $actions = collect(
            $this->getJson("/api/deals/{$deal->id}")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        // last_touch = the call (meeting excluded); last_event = the meeting (newer).
        $this->assertSame($call->completed_at->toIso8601String(), $actions['last_touch']['date']);
        $this->assertNotNull($actions['last_event']['date']);
    }

    public function test_last_event_counts_meeting_call_presentation(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);

        $this->completedActivity($deal, ActivityType::Call, now()->subDays(3));
        $meeting = $this->completedActivity($deal, ActivityType::Meeting, now()->subDay());

        Sanctum::actingAs($user, ['*']);

        $actions = collect(
            $this->getJson("/api/deals/{$deal->id}")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        $this->assertSame($meeting->completed_at->toIso8601String(), $actions['last_event']['date']);
    }

    public function test_max_stage_ref_reflects_highest_reached_stage(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user, 'new');
        $pipeline = $deal->pipeline;
        $hot = $pipeline->stages->firstWhere('code', 'hot');
        $qualify = $pipeline->stages->firstWhere('code', 'qualify');
        Sanctum::actingAs($user, ['*']);

        // Forward to hot, then roll BACK to qualify.
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $hot->id])->assertOk();
        $this->postJson("/api/deals/{$deal->id}/move", ['to_stage_id' => $qualify->id])->assertOk();

        $actions = collect(
            $this->getJson("/api/deals/{$deal->id}")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        // Current stage is qualify, but max_stage stays hot (the high-water mark).
        $this->assertSame($hot->id, $actions['max_stage']['ref']['stage_id']);
        $this->assertSame($hot->name, $actions['max_stage']['ref']['name']);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'stage_id' => $qualify->id,
            'max_stage_id' => $hot->id,
        ]);
    }

    public function test_mark_kp_sent_stamps_field_and_logs(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $this->assertNull($deal->kp_sent_at);

        $actions = collect(
            $this->postJson("/api/deals/{$deal->id}/kp-sent")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        $this->assertNotNull($actions['kp_sent']['date']);
        $this->assertNotNull($deal->fresh()->kp_sent_at);

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'kp_sent',
            'actor_id' => $user->id,
        ]);
    }

    public function test_mark_contract_sent_stamps_field_and_logs(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $deal = $this->dealFor($user);
        Sanctum::actingAs($user, ['*']);

        $actions = collect(
            $this->postJson("/api/deals/{$deal->id}/contract-sent")->assertOk()->json('data.key_actions'),
        )->keyBy('type');

        $this->assertNotNull($actions['contract_sent']['date']);
        $this->assertNotNull($deal->fresh()->contract_sent_at);

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'contract_sent',
            'actor_id' => $user->id,
        ]);
    }

    public function test_mark_kp_sent_forbidden_for_foreign_manager(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $stranger = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->dealFor($owner);
        Sanctum::actingAs($stranger, ['*']);

        $this->postJson("/api/deals/{$deal->id}/kp-sent")->assertForbidden();

        $this->assertNull($deal->fresh()->kp_sent_at);
    }
}
