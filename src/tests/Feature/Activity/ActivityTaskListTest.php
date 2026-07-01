<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Sales\Models\PipelineStage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Backend surface for the task list 2.0 (Задачник): the list endpoint enriches
 * deal-targeted rows with deal/company/stage context (no N+1), inline edit of
 * the task type, completing with a result, and the quick due-date shift.
 */
class ActivityTaskListTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    public function test_list_returns_linked_deal_company_and_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()
            ->forDeal($deal)
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id)
            ->assertJsonPath('data.0.deal.id', $deal->id)
            ->assertJsonPath('data.0.deal.title', $deal->title)
            ->assertJsonPath('data.0.deal.company.id', $deal->company_id)
            ->assertJsonPath('data.0.deal.stage.id', $deal->stage_id);

        // The stage status flags (deal status) come through for the column.
        $response->assertJsonPath('data.0.deal.stage.is_won', false);
        $response->assertJsonPath('data.0.deal.stage.is_lost', false);
    }

    public function test_list_deal_context_is_null_for_standalone_task(): void
    {
        $manager = $this->manager();
        Activity::factory()->standalone()->responsibleOf($manager)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('data.0.deal', null);
    }

    public function test_list_does_not_n_plus_one_on_deal_context(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();

        // Three activities across two distinct deals.
        $dealA = $this->dealFor($manager, $pipeline);
        $dealB = $this->dealFor($manager, $pipeline);
        Activity::factory()->forDeal($dealA)->responsibleOf($manager)->createdByUser($manager)->create();
        Activity::factory()->forDeal($dealA)->responsibleOf($manager)->createdByUser($manager)->create();
        Activity::factory()->forDeal($dealB)->responsibleOf($manager)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        $queries = 0;
        \DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/activities')->assertOk()->assertJsonCount(3, 'data');

        // The deal context is resolved in a bounded number of queries (one for
        // deals, one for stages, one for companies via eager-load) regardless of
        // how many activities point at the same deal — no per-row deal lookup.
        $this->assertLessThanOrEqual(12, $queries, "Too many queries ({$queries}) — deal context is N+1.");
    }

    // ---- D2: GET /api/activities honours the FilterPanel query params ----

    public function test_list_filters_by_responsible_id_within_scope(): void
    {
        // A director (All scope) sees every manager's tasks; responsible_id must
        // narrow the list to exactly one manager's tasks. This is the param the FE
        // collected but the backend previously ignored.
        $director = $this->director();
        $alice = $this->manager();
        $bob = $this->manager();

        $aliceTask = Activity::factory()->responsibleOf($alice)->createdByUser($alice)->create();
        Activity::factory()->responsibleOf($bob)->createdByUser($bob)->create();

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities?responsible_id='.$alice->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $aliceTask->id);
    }

    public function test_list_responsible_id_stays_within_visibility_scope(): void
    {
        // An Own-scope manager filtering by another user's id sees nothing — the
        // filter narrows WITHIN the scope, it never widens past it.
        $alice = $this->manager();
        $bob = $this->manager();

        Activity::factory()->responsibleOf($bob)->createdByUser($bob)->create();

        Sanctum::actingAs($alice, ['*']);

        $this->getJson('/api/activities?responsible_id='.$bob->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_filters_by_status(): void
    {
        $manager = $this->manager();

        $done = Activity::factory()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)->create(); // new

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities?status[]=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);
    }

    public function test_list_filters_by_priority(): void
    {
        $manager = $this->manager();

        $high = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['priority' => ActivityPriority::High->value]);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['priority' => ActivityPriority::Low->value]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities?priority[]=high')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $high->id);
    }

    public function test_list_filters_by_kind(): void
    {
        $manager = $this->manager();

        $call = Activity::factory()->call()->responsibleOf($manager)->createdByUser($manager)->create();
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities?kind[]=call')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $call->id);
    }

    public function test_list_q_searches_title_and_body(): void
    {
        $manager = $this->manager();

        $byTitle = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Подписать контракт', 'body' => null]);
        $byBody = Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Звонок клиенту', 'body' => 'Обсудить контракт по телефону']);
        Activity::factory()->responsibleOf($manager)->createdByUser($manager)
            ->create(['title' => 'Встреча', 'body' => 'Демо продукта']);

        Sanctum::actingAs($manager, ['*']);

        $ids = collect($this->getJson('/api/activities?q=контракт')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($byTitle->id, $ids, 'q must match the title');
        $this->assertContains($byBody->id, $ids, 'q must match the body');
        $this->assertCount(2, $ids, 'q must NOT match the unrelated row');
    }

    public function test_list_combines_responsible_and_status_filters(): void
    {
        $director = $this->director();
        $alice = $this->manager();
        $bob = $this->manager();

        $aliceDone = Activity::factory()->responsibleOf($alice)->createdByUser($alice)->completed($alice)->create();
        Activity::factory()->responsibleOf($alice)->createdByUser($alice)->create(); // alice, new
        Activity::factory()->responsibleOf($bob)->createdByUser($bob)->completed($bob)->create(); // bob, done

        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/activities?responsible_id='.$alice->id.'&status[]=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $aliceDone->id);
    }

    public function test_inline_edit_changes_task_type(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['kind' => ActivityType::Call->value])
            ->assertOk()
            ->assertJsonPath('data.kind', 'call');
    }

    public function test_inline_kind_edit_respects_stage_task_types_gate(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()
            ->forDeal($deal)
            ->state(['kind' => ActivityType::Call->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        // Restrict the stage to calls only — switching to meeting must be blocked.
        PipelineStage::whereKey($deal->stage_id)->update(['task_types' => ['call']]);

        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}", ['kind' => ActivityType::Meeting->value])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('kind');
    }

    public function test_complete_saves_result_text(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete", ['result_text' => 'Client agreed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result_text', 'Client agreed');

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'result_text' => 'Client agreed',
        ]);
    }

    public function test_complete_without_result_still_works(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_reschedule_tomorrow_moves_due_to_day_after_existing_due(): void
    {
        // Freeze the clock so the service and the test resolve the operational day
        // start from the same instant (the boundary math is otherwise racy at a
        // Dubai-midnight crossing). 10:00 UTC = 14:00 Dubai — mid-day in both.
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $manager = $this->manager();

        // A task already due 3 days ago: "+1d / tomorrow" anchors on its EXISTING
        // due day and adds a day — it must NOT reset to today+1 (the bug a future
        // task hit, jumping backwards).
        $existingDue = Carbon::parse('2026-03-12 10:00:00', 'UTC'); // 14:00 Dubai
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => $existingDue])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        // Start of the day AFTER the existing due day, in the operational timezone.
        $tz = config('salespulse.timezone', 'Asia/Dubai');
        $expected = $existingDue->copy()->setTimezone($tz)->startOfDay()->addDay()->utc();

        $res = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'tomorrow'])
            ->assertOk();

        $this->assertTrue(
            Carbon::parse($res->json('data.due_at'))->equalTo($expected),
            'reschedule tomorrow should land on the start of the day after the existing due date',
        );

        Carbon::setTestNow();
    }

    public function test_reschedule_plus_1d_on_future_task_moves_forward_not_backward(): void
    {
        // Regression for the reported bug: a task due tomorrow 04:00 Dubai (UTC+4)
        // hit with "+1d" jumped BACKWARD to start-of-today instead of forward to
        // the day after its existing due date.
        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00', 'UTC'));

        $manager = $this->manager();

        // Due 2026-06-26 04:00 Dubai = 2026-06-26 00:00 UTC — a future deadline.
        $tz = config('salespulse.timezone', 'Asia/Dubai');
        $existingDue = Carbon::parse('2026-06-26 04:00:00', $tz);
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => $existingDue])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        // +1d must move to start of 2026-06-27 in the operational timezone, NOT
        // back to start of today (2026-06-25).
        $expected = Carbon::parse('2026-06-27 00:00:00', $tz)->utc();

        $res = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => '+1d'])
            ->assertOk();

        $due = Carbon::parse($res->json('data.due_at'));

        $this->assertTrue(
            $due->equalTo($expected),
            '+1d on a future task must move forward to the day after its due date, not backward to today',
        );
        $this->assertTrue(
            $due->greaterThan($existingDue),
            '+1d must never produce a due_at earlier than the existing one',
        );

        Carbon::setTestNow();
    }

    public function test_reschedule_next_week_and_next_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $tz = config('salespulse.timezone', 'Asia/Dubai');

        $manager = $this->manager();

        // Anchor on a known existing due day (2026-03-17 14:00 Dubai) so the shift
        // is measured from the deadline, not from the clock.
        $existingDue = Carbon::parse('2026-03-17 14:00:00', $tz);
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => $existingDue])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $anchorDayStart = $existingDue->copy()->setTimezone($tz)->startOfDay();

        $week = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_week'])
            ->assertOk();
        $this->assertTrue(
            Carbon::parse($week->json('data.due_at'))->equalTo($anchorDayStart->copy()->addWeek()->utc()),
        );

        // After the next_week shift the activity's due day is now one week later;
        // next_month anchors on THAT new due day.
        $newAnchor = $anchorDayStart->copy()->addWeek();
        $month = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_month'])
            ->assertOk();
        $this->assertTrue(
            Carbon::parse($month->json('data.due_at'))->equalTo($newAnchor->copy()->addMonthNoOverflow()->utc()),
        );

        Carbon::setTestNow();
    }

    public function test_reschedule_shortcut_presets_plus_1d_plus_1w_next_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $tz = config('salespulse.timezone', 'Asia/Dubai');

        $manager = $this->manager();

        // Existing due 2026-03-17 (a Tuesday) 14:00 Dubai — the anchor for all
        // shortcuts.
        $existingDue = Carbon::parse('2026-03-17 14:00:00', $tz);
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => $existingDue])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $anchorDayStart = $existingDue->copy()->setTimezone($tz)->startOfDay();

        $plus1d = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => '+1d'])
            ->assertOk();
        $this->assertTrue(
            Carbon::parse($plus1d->json('data.due_at'))->equalTo($anchorDayStart->copy()->addDay()->utc()),
            '+1d should add a day to the existing due day',
        );

        // Re-anchor: the due day is now 2026-03-18; +1w from there.
        $afterPlus1d = $anchorDayStart->copy()->addDay();
        $plus1w = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => '+1w'])
            ->assertOk();
        $this->assertTrue(
            Carbon::parse($plus1w->json('data.due_at'))->equalTo($afterPlus1d->copy()->addWeek()->utc()),
            '+1w should be one week from the existing due day',
        );

        // Re-anchor: the due day is now 2026-03-25 (a Wednesday); next Monday
        // strictly after it is 2026-03-30.
        $afterPlus1w = $afterPlus1d->copy()->addWeek();
        $nextMon = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_monday'])
            ->assertOk();
        $expectedMon = $afterPlus1w->copy()->next(CarbonInterface::MONDAY)->utc();
        $this->assertTrue(
            Carbon::parse($nextMon->json('data.due_at'))->equalTo($expectedMon),
            'next_monday should land on the next Monday after the existing due day',
        );

        Carbon::setTestNow();
    }

    public function test_reschedule_preset_anchors_on_today_when_no_existing_due(): void
    {
        // A task with no deadline yet falls back to anchoring on today.
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $tz = config('salespulse.timezone', 'Asia/Dubai');

        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => null])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $expected = Carbon::now($tz)->startOfDay()->addDay()->utc();

        $res = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => '+1d'])
            ->assertOk();
        $this->assertTrue(
            Carbon::parse($res->json('data.due_at'))->equalTo($expected),
            'a deadline-less task anchors +1d on today',
        );

        Carbon::setTestNow();
    }

    public function test_reschedule_accepts_explicit_due_at(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => now()->subDays(2)])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $target = Carbon::parse('2026-05-20 09:00:00', 'UTC');

        $res = $this->postJson("/api/activities/{$activity->id}/reschedule", [
            'due_at' => $target->toIso8601String(),
        ])->assertOk();

        $this->assertTrue(
            Carbon::parse($res->json('data.due_at'))->equalTo($target),
            'explicit due_at should be persisted as the absolute instant given',
        );
    }

    public function test_reschedule_only_moves_due_at_and_leaves_status_untouched(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state([
                'kind' => ActivityType::Task->value,
                'status' => ActivityStatus::InProgress->value,
                'is_closed' => false,
                'due_at' => now()->subDays(5),
            ])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'tomorrow'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.is_closed', false);

        $activity->refresh();
        $this->assertSame(ActivityStatus::InProgress, $activity->status);
        $this->assertFalse($activity->is_closed);
        $this->assertNull($activity->completed_at);
    }

    public function test_reschedule_requires_exactly_one_of_preset_or_due_at(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        // Neither → required_without on both fires.
        $this->postJson("/api/activities/{$activity->id}/reschedule", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('preset');

        // Both → prohibits:due_at on the preset rule fires.
        $this->postJson("/api/activities/{$activity->id}/reschedule", [
            'preset' => 'tomorrow',
            'due_at' => '2026-05-20T09:00:00Z',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('preset');
    }

    public function test_reschedule_forbidden_for_foreign_manager(): void
    {
        $owner = $this->manager();
        $stranger = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($owner)
            ->createdByUser($owner)
            ->create();

        Sanctum::actingAs($stranger, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'tomorrow'])
            ->assertForbidden();
    }

    public function test_reschedule_rejects_unknown_preset(): void
    {
        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_year'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('preset');
    }

    public function test_reschedule_rejected_on_note(): void
    {
        $manager = $this->manager();
        $note = Activity::factory()->note()->responsibleOf($manager)->createdByUser($manager)->create();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/activities/{$note->id}/reschedule", ['preset' => 'tomorrow'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('kind');
    }
}
