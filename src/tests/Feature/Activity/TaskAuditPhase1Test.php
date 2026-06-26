<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Models\DealContact;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-1 latent-bug cleanup from the 2026-06-26 task-management audit:
 *   D11/D13 — a rejected task is treated as closed (never open/overdue) even
 *             when its is_closed flag disagrees with its status;
 *   A1      — a deal-task completion fans the task_completed/meeting_held log
 *             out to the deal's company + each linked contact, single-fire;
 *   A5      — myOpenCount is visibility-scoped (count == list);
 *   F23     — countsByPreset returns numbers identical to per-preset counts in
 *             one query (incl. 'completed');
 *   E18     — GET /activities yields null deal context for a now-foreign deal;
 *   item 8  — the 'completed' preset is ordered by completed_at desc.
 */
class TaskAuditPhase1Test extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    // ---- D11/D13: rejected is closed for open/overdue predicates ----

    public function test_rejected_task_is_not_overdue_even_when_not_closed(): void
    {
        // A rejected task with a past due_at and is_closed = false (a deliberate
        // status/is_closed disagreement) must NOT be reported overdue — isOverdue()
        // short-circuits on a FINAL status, not only on Done.
        $manager = $this->manager();
        $activity = Activity::factory()->task()
            ->responsibleOf($manager)->createdByUser($manager)
            ->create([
                'status' => ActivityStatus::Rejected->value,
                'is_closed' => false,
                'due_at' => now()->subDays(3),
            ]);

        $this->assertFalse($activity->isOverdue());
    }

    public function test_rejected_task_absent_from_overdue_preset_and_board(): void
    {
        $manager = $this->manager();

        // A genuinely overdue (open) task — appears.
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->overdue()->create();
        // A rejected task with a past due, is_closed = false — must NOT appear in
        // either the overdue preset or the my-board overdue bucket.
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create([
                'status' => ActivityStatus::Rejected->value,
                'is_closed' => false,
                'due_at' => now()->subDays(3),
            ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/presets/overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $board = $this->getJson('/api/activities/my-board')->assertOk()->json('data');
        $this->assertCount(1, $board['overdue']);
    }

    public function test_rejected_task_does_not_inflate_my_open_count(): void
    {
        $manager = $this->manager();

        // Two genuinely open tasks.
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)->count(2)->create();
        // A rejected task with is_closed = false — must not count as open (D11/D13).
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::Rejected->value, 'is_closed' => false]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities/my-open-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_rejected_task_absent_from_deal_next_task(): void
    {
        // nextTasksForDeals must skip a rejected (non-final-clean) task even if its
        // is_closed flag desynced — it is no longer "the next open task".
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create([
                'status' => ActivityStatus::Rejected->value,
                'is_closed' => false,
                'due_at' => now()->addDay(),
            ]);

        $map = app(ActivityService::class)->nextTasksForDeals([(int) $deal->id]);

        $this->assertArrayNotHasKey((int) $deal->id, $map);
    }

    // ---- A5: myOpenCount is visibility-scoped (count == list) ----

    public function test_my_open_count_never_exceeds_scoped_my_tasks_list(): void
    {
        $manager = $this->manager();
        $stranger = $this->manager();

        // Two tasks the manager owns.
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)->count(2)->create();
        // A task owned + created by a stranger (out of an Own-scoped manager's
        // visibility). The badge must NOT count it — count == the scoped list.
        Activity::factory()->task()->responsibleOf($stranger)->createdByUser($stranger)->create();

        Sanctum::actingAs($manager, ['*']);

        $count = $this->getJson('/api/activities/my-open-count')->assertOk()->json('data.count');
        $list = $this->getJson('/api/activities/presets/my_tasks')->assertOk()->json('data');

        $this->assertSame(count($list), $count);
        $this->assertSame(2, $count);
    }

    // ---- A1: completion log fans out to company + linked contacts ----

    public function test_deal_task_completion_logs_on_company_and_each_linked_contact(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director(); // All scope — can complete on any deal
        $deal = $this->dealFor($manager, $pipeline);

        $contactA = $this->contactFor($manager);
        $contactB = $this->contactFor($manager);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contactA->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contactB->id]);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->complete($activity, $manager);

        // One row on the deal (direct subject).
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'deal', 'subject_id' => $deal->id, 'action' => 'task_completed',
        ])->count());
        // One row on the deal's company.
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'company', 'subject_id' => $deal->company_id, 'action' => 'task_completed',
        ])->count());
        // One row on EACH linked contact.
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'contact', 'subject_id' => $contactA->id, 'action' => 'task_completed',
        ])->count());
        $this->assertSame(1, \DB::table('entity_logs')->where([
            'subject_type' => 'contact', 'subject_id' => $contactB->id, 'action' => 'task_completed',
        ])->count());
    }

    public function test_meeting_completion_fans_out_meeting_held_log(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $contact = $this->contactFor($manager);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        $meeting = Activity::factory()->meeting()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->complete($meeting, $manager);

        // A meeting fans out as meeting_held (not task_completed) on every target.
        foreach ([
            ['company', $deal->company_id],
            ['contact', $contact->id],
        ] as [$type, $id]) {
            $this->assertDatabaseHas('entity_logs', [
                'subject_type' => $type, 'subject_id' => $id, 'action' => 'meeting_held',
            ]);
        }
    }

    public function test_completion_fan_out_is_single_fire_on_double_submit(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $contact = $this->contactFor($manager);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $service = app(ActivityService::class);
        $service->complete($activity, $manager);
        $service->complete($activity, $manager); // racing double-submit

        // deal + company + 1 contact = exactly 3 rows, never 6 (B3 single-fire).
        $this->assertDatabaseCount('entity_logs', 3);
    }

    public function test_completion_does_not_fan_out_for_company_target(): void
    {
        // A directly company-targeted completion logs only on the company; there is
        // no deal fan-out to dedupe against.
        $manager = $this->director();
        $company = $this->companyFor($manager);

        $activity = Activity::factory()->task()->forCompany($company)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->complete($activity, $manager);

        $this->assertDatabaseCount('entity_logs', 1);
        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'company', 'subject_id' => $company->id, 'action' => 'task_completed',
        ]);
    }

    // ---- F23: countsByPreset is one query, identical to per-preset counts ----

    public function test_counts_by_preset_identical_to_per_preset_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-16 08:00:00', 'UTC'));

        $manager = $this->manager();

        // A spread across the presets: overdue, today, this-week, pinned, completed,
        // rejected and an order placed for someone else.
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)->overdue()->create();
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->setTime(12, 0)]);
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['due_at' => now()->addDays(2)]);
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['is_pinned' => true]);
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)->completed($manager)->create();
        Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::Rejected->value, 'is_closed' => true]);

        $service = app(ActivityService::class);
        $scope = VisibilityScope::Own;

        $counts = $service->countsByPreset($scope, $manager);

        // The single-query roll-up must match a per-preset ->count() exactly.
        foreach (['my_tasks', 'my_orders', 'overdue', 'today', 'this_week', 'pinned', 'completed'] as $preset) {
            $expected = $service->presets($preset, $scope, $manager, 100)->total();
            $this->assertSame($expected, $counts[$preset], "preset {$preset} count mismatch");
        }

        $this->assertArrayHasKey('completed', $counts);

        Carbon::setTestNow();
    }

    // ---- E18: now-foreign deal context is null in GET /activities ----

    public function test_list_yields_null_deal_context_for_now_foreign_deal(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $stranger = $this->manager();

        // A deal owned by a stranger (out of the manager's Own scope), but the
        // manager is the responsible on a task targeting it — a task that moved out
        // of scope. The activity row is still theirs (visible), but the deal context
        // must NOT leak the now-foreign deal's title/stage/company.
        $foreignDeal = $this->dealFor($stranger, $pipeline);

        Activity::factory()->task()->forDeal($foreignDeal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data')      // the activity is still visible
            ->assertJsonPath('data.0.deal', null); // but its foreign deal context is hidden
    }

    public function test_list_yields_deal_context_for_own_deal(): void
    {
        // Control: the SAME enrichment still returns context for a deal the manager
        // can see — the scope hides only foreign deals, not own.
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->manager();
        $ownDeal = $this->dealFor($manager, $pipeline);

        Activity::factory()->task()->forDeal($ownDeal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create();

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('data.0.deal.id', $ownDeal->id);
    }

    // ---- item 8: completed preset ordered by completed_at desc ----

    public function test_completed_preset_ordered_by_completed_at_desc(): void
    {
        $manager = $this->manager();

        $older = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create(['completed_at' => now()->subDays(5)]);
        $newer = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create(['completed_at' => now()->subDay()]);

        Sanctum::actingAs($manager, ['*']);

        $data = $this->getJson('/api/activities/presets/completed')->assertOk()->json('data');

        // Most recently completed first.
        $this->assertSame($newer->id, $data[0]['id']);
        $this->assertSame($older->id, $data[1]['id']);
        // The resource exposes completed_at for the «Выполненные» tab.
        $this->assertNotNull($data[0]['completed_at']);
    }

    public function test_completed_at_nulls_sort_last_in_completed_preset(): void
    {
        $manager = $this->manager();

        // A rejected (closed, no completed_at) row and a done row with completed_at.
        $rejected = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::Rejected->value, 'is_closed' => true, 'completed_at' => null]);
        $done = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create(['completed_at' => now()->subHour()]);

        Sanctum::actingAs($manager, ['*']);

        $data = $this->getJson('/api/activities/presets/completed')->assertOk()->json('data');

        // The completed (non-null completed_at) row sorts before the null one.
        $this->assertSame($done->id, $data[0]['id']);
        $this->assertSame($rejected->id, $data[1]['id']);
        $this->assertNull($data[1]['completed_at']);
    }

    // ---- D14: ChangeStatusRequest ignores a client-supplied is_closed ----

    public function test_change_status_ignores_client_supplied_is_closed(): void
    {
        // is_closed is server-derived. A client passing is_closed in the body must
        // NOT be able to steer the close flag: a transition to in_progress keeps the
        // task open regardless of the spoofed is_closed = true.
        $manager = $this->manager();
        $activity = Activity::factory()->task()->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::New->value, 'is_closed' => false]);

        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/activities/{$activity->id}/status", [
            'status' => 'in_progress',
            'is_closed' => true, // spoofed — must be ignored, not validated-in
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.is_closed', false);

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'status' => 'in_progress',
            'is_closed' => false,
        ]);
    }
}
