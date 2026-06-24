<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Sales\Models\PipelineStage;
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

    public function test_reschedule_tomorrow_moves_due_to_start_of_tomorrow(): void
    {
        // Freeze the clock so the service and the test resolve the operational day
        // start from the same instant (the boundary math is otherwise racy at a
        // Dubai-midnight crossing). 10:00 UTC = 14:00 Dubai — mid-day in both.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value, 'due_at' => now()->subDays(3)])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        // Day boundaries are computed as the true instant of the operational
        // timezone midnight (Дубай-окно), not the UTC server clock (MINOR-8).
        // Compare absolute instants (both normalised to UTC).
        $tz = config('salespulse.timezone', 'Asia/Dubai');
        $expected = \Carbon\Carbon::now($tz)->startOfDay()->addDay()->utc();

        $res = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'tomorrow'])
            ->assertOk();

        $this->assertTrue(
            \Carbon\Carbon::parse($res->json('data.due_at'))->equalTo($expected),
            'reschedule tomorrow should land on the operational start of tomorrow',
        );

        \Carbon\Carbon::setTestNow();
    }

    public function test_reschedule_next_week_and_next_month(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $manager = $this->manager();
        $activity = Activity::factory()
            ->state(['kind' => ActivityType::Task->value])
            ->responsibleOf($manager)
            ->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $tz = config('salespulse.timezone', 'Asia/Dubai');
        $dayStart = \Carbon\Carbon::now($tz)->startOfDay()->utc();

        $week = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_week'])
            ->assertOk();
        $this->assertTrue(
            \Carbon\Carbon::parse($week->json('data.due_at'))->equalTo($dayStart->copy()->addWeek()),
        );

        $month = $this->postJson("/api/activities/{$activity->id}/reschedule", ['preset' => 'next_month'])
            ->assertOk();
        $this->assertTrue(
            \Carbon\Carbon::parse($month->json('data.due_at'))->equalTo($dayStart->copy()->addMonthNoOverflow()),
        );

        \Carbon\Carbon::setTestNow();
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
