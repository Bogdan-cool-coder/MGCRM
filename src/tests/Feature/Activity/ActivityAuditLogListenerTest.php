<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C8 — the activity action-journal (EntityLog) rows are now written by
 * RecordActivityAuditLogListener off the ActivityCreated / ActivityStatusChanged
 * events, NOT inline inside ActivityService. These tests run the real event
 * dispatcher (events are NOT faked) and assert each transition produces EXACTLY
 * the expected rows through the listener, with no double-write. The refactor is a
 * pure move of WHERE the write happens — the rows, the meta and the single-fire
 * are byte-identical to the previous inline behavior (the broader
 * ActivityActionLogTest + TaskAuditPhase1Test cover the same surface via HTTP).
 */
class ActivityAuditLogListenerTest extends TestCase
{
    use ActivityTestHelpers;
    use RefreshDatabase;

    // ---- create-note → exactly one note_added row via the listener ----

    public function test_create_note_writes_exactly_one_note_added_row(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = app(ActivityService::class)->create([
            'kind' => 'note',
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'A logged note',
        ], $manager);

        // Exactly one row, on the deal, authored by the creator, with the canonical
        // meta shape — and no fan-out (a note is NOT fanned out to company/contacts).
        $this->assertDatabaseCount('entity_logs', 1);
        $row = DB::table('entity_logs')->first();
        $this->assertSame('deal', $row->subject_type);
        $this->assertSame((int) $deal->id, (int) $row->subject_id);
        $this->assertSame('note_added', $row->action);
        $this->assertSame($manager->id, (int) $row->actor_id);

        $meta = json_decode((string) $row->meta, true);
        $this->assertSame((int) $activity->id, $meta['activity_id']);
        $this->assertSame('note', $meta['kind']);
        $this->assertSame('A logged note', $meta['title']);
    }

    public function test_non_note_create_writes_no_log(): void
    {
        // A task/call/meeting created (not completed) writes no journal row — its
        // rows come from later status transitions, exactly as before.
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        app(ActivityService::class)->create([
            'kind' => 'task',
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'title' => 'A task, not a note',
        ], $manager);

        $this->assertDatabaseCount('entity_logs', 0);
    }

    public function test_standalone_note_writes_no_log_via_listener(): void
    {
        $manager = $this->manager();

        app(ActivityService::class)->create([
            'kind' => 'note',
            'title' => 'Personal note, no target',
        ], $manager);

        $this->assertDatabaseCount('entity_logs', 0);
    }

    // ---- complete → completion rows + fan-out via the listener, single-fire ----

    public function test_complete_writes_completion_rows_with_fan_out_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);
        $contact = $this->contactFor($manager);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->complete($activity, $manager);

        // deal (direct) + company + 1 contact = exactly 3 task_completed rows.
        $this->assertDatabaseCount('entity_logs', 3);
        foreach ([['deal', $deal->id], ['company', $deal->company_id], ['contact', $contact->id]] as [$type, $id]) {
            $this->assertSame(1, DB::table('entity_logs')->where([
                'subject_type' => $type, 'subject_id' => $id, 'action' => 'task_completed',
            ])->count());
        }
        // Actor is stamped from the event (the completing user), not lost in the move.
        $this->assertSame(
            3,
            DB::table('entity_logs')->where('actor_id', $manager->id)->count(),
        );
    }

    public function test_complete_is_single_fire_via_listener_no_duplicate_rows(): void
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

        // The B3 conditional-UPDATE means the event fires once → the listener writes
        // once: deal + company + contact = 3 rows, never 6.
        $this->assertDatabaseCount('entity_logs', 3);
    }

    public function test_meeting_completion_writes_meeting_held_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $meeting = Activity::factory()->meeting()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->complete($meeting, $manager);

        // A meeting fans out as meeting_held (not task_completed).
        $this->assertSame(0, DB::table('entity_logs')->where('action', 'task_completed')->count());
        $this->assertSame(2, DB::table('entity_logs')->where('action', 'meeting_held')->count()); // deal + company
    }

    // ---- reopen → exactly one task_reopened row via the listener ----

    public function test_reopen_writes_exactly_one_task_reopened_row_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create();

        app(ActivityService::class)->reopen($activity, $manager);

        // The deal is the only journalled subject for a reopen (no fan-out for
        // reopen — only completions fan out).
        $this->assertSame(1, DB::table('entity_logs')->where('action', 'task_reopened')->count());
        $this->assertSame(1, DB::table('entity_logs')->where([
            'subject_type' => 'deal', 'subject_id' => $deal->id, 'action' => 'task_reopened',
        ])->count());
    }

    public function test_reopen_is_single_fire_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->completed($manager)->create();

        $service = app(ActivityService::class);
        $service->reopen($activity, $manager);
        $service->reopen($activity, $manager); // double-submit — second is a no-op

        $this->assertSame(1, DB::table('entity_logs')->where('action', 'task_reopened')->count());
    }

    // ---- reject → exactly one task_rejected row via the listener ----

    public function test_reject_writes_exactly_one_task_rejected_row_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        app(ActivityService::class)->changeStatus($activity, ActivityStatus::Rejected, $manager);

        $this->assertSame(1, DB::table('entity_logs')->where('action', 'task_rejected')->count());
        $this->assertSame(1, DB::table('entity_logs')->where([
            'subject_type' => 'deal', 'subject_id' => $deal->id, 'action' => 'task_rejected',
        ])->count());
    }

    public function test_reject_is_single_fire_via_listener(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::InProgress->value, 'is_closed' => false]);

        $service = app(ActivityService::class);
        $service->changeStatus($activity, ActivityStatus::Rejected, $manager);
        $service->changeStatus($activity, ActivityStatus::Rejected, $manager); // no-op

        $this->assertSame(1, DB::table('entity_logs')->where('action', 'task_rejected')->count());
    }

    // ---- a plain open-state transition is NOT journalled (byte-identical) ----

    public function test_new_to_in_progress_writes_no_log(): void
    {
        // Only a genuine reopen (done → in_progress) writes task_reopened. A plain
        // new → in_progress transition wrote nothing inline and must write nothing
        // through the listener either.
        $pipeline = $this->seedSalesPipeline();
        $manager = $this->director();
        $deal = $this->dealFor($manager, $pipeline);

        $activity = Activity::factory()->task()->forDeal($deal)
            ->responsibleOf($manager)->createdByUser($manager)
            ->create(['status' => ActivityStatus::New->value, 'is_closed' => false]);

        app(ActivityService::class)->changeStatus($activity, ActivityStatus::InProgress, $manager);

        $this->assertDatabaseCount('entity_logs', 0);
    }
}
