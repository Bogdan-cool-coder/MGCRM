<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit B1/B2/B3: notes, reopen and reject must each append exactly ONE row to
 * the canonical action journal (/log = EntityLog), and complete() must be
 * single-fire under a double-submit (one log row, engagement bumped once).
 */
class ActivityActionLogTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    // ---- B1: notes write a note_added log row on their target ----

    public function test_note_on_deal_writes_note_added_log(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director(); // director: All scope, can target any deal
        $deal = $this->dealFor($manager, $pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => 'note',
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'Called the client',
        ])->assertCreated();

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'note_added',
        ]);
    }

    public function test_standalone_note_writes_no_log(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/activities', [
            'kind' => 'note',
            'title' => 'Personal reminder',
        ])->assertCreated();

        $this->assertDatabaseCount('entity_logs', 0);
    }

    // ---- B2: reopen / reject write their own log rows ----

    public function test_reopen_writes_task_reopened_log(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'task_reopened',
        ]);
    }

    public function test_reject_writes_task_rejected_log(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.is_closed', true);

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'task_rejected',
        ]);
    }

    public function test_standalone_reopen_and_reject_write_no_log(): void
    {
        $manager = $this->manager();
        $reopenable = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create();
        $rejectable = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$reopenable->id}/reopen")->assertOk();
        $this->patchJson("/api/activities/{$rejectable->id}/status", ['status' => 'rejected'])->assertOk();

        // No target → no card to log against.
        $this->assertDatabaseCount('entity_logs', 0);
    }

    // ---- B3: complete() is single-fire under a double-submit ----

    public function test_complete_is_single_fire_on_repeated_calls(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        // Call the service directly twice — the SAME in-memory model instance,
        // simulating a double-submit racing on one row. Only the first flip owns
        // the side-effects (log + engagement).
        $service = app(ActivityService::class);
        $service->complete($activity, $manager);
        $service->complete($activity, $manager);

        // B3 single-fire: the deal subject gets EXACTLY one task_completed row even
        // across the double-submit (the A1 fan-out additionally writes one row on
        // the deal's company — that fan-out is part of the single-fire side-effect,
        // so the company row is also written at most once: 2 rows total, never 4).
        $this->assertDatabaseCount('entity_logs', 2);
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'task_completed',
        ])->count());
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'company',
            'subject_id' => $deal->company_id,
            'action' => 'task_completed',
        ])->count());
    }

    public function test_complete_bumps_engagement_once(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $company = $deal->company; // engagement target

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $service = app(ActivityService::class);

        // First complete at a frozen instant stamps engagement.
        Carbon::setTestNow('2026-03-16 10:00:00');
        $service->complete($activity, $manager);
        $firstTouch = $company->fresh()->last_activity_at;

        // Advance the clock, then complete again on the already-done row: if the
        // side-effect re-fired, last_activity_at would jump to the new instant.
        Carbon::setTestNow('2026-03-16 12:00:00');
        $service->complete($activity, $manager);
        $secondTouch = $company->fresh()->last_activity_at;

        Carbon::setTestNow();

        $this->assertNotNull($firstTouch);
        $this->assertEquals(
            $firstTouch->toIso8601String(),
            $secondTouch->toIso8601String(),
            'Engagement must be bumped at most once across repeated completes.'
        );
    }

    public function test_idempotent_complete_returns_done_without_extra_log(): void
    {
        // The HTTP idempotent path (already done) must not double-log.
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create();
        Sanctum::actingAs($manager, ['*']);

        // Already done at creation → completing again is a no-op, no log.
        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertDatabaseCount('entity_logs', 0);
    }
}
