<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Enums\AnnouncedEventType;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseAnnouncedEvent;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use Database\Seeders\PipelineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 0 foundation: the four SalesPulse tables build, and each model round-
 * trips through Eloquent (jsonb payload, enum casts, date/datetime casts,
 * relations) with the unique constraints enforced. No business logic is
 * exercised yet — that lands in Slices 1-4.
 */
class SalesPulseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_snapshot_round_trips_jsonb_and_enum_casts(): void
    {
        $manager = User::factory()->create();

        $payload = [
            'manager_id' => $manager->id,
            'manager_name' => 'Илья Рогов',
            'on_date' => '2026-06-22',
            'tasks' => [
                ['task_id' => 1, 'text' => 'Позвонить', 'kind' => 'call', 'deal_id' => 42],
            ],
            'leads_by_id' => [
                '42' => ['name' => 'Apart Developer', 'status_id' => 7, 'responsible_user_id' => $manager->id],
            ],
        ];

        $snapshot = PulseSnapshot::create([
            'manager_id' => $manager->id,
            'on_date' => '2026-06-22',
            'kind' => SnapKind::Plan,
            'source' => SnapSource::Manual,
            'captured_at' => now(),
            'data' => $payload,
        ]);

        $fresh = $snapshot->fresh();

        $this->assertInstanceOf(SnapKind::class, $fresh->kind);
        $this->assertSame(SnapKind::Plan, $fresh->kind);
        $this->assertSame(SnapSource::Manual, $fresh->source);
        $this->assertSame('2026-06-22', $fresh->on_date->toDateString());
        $this->assertIsArray($fresh->data);
        $this->assertSame($payload, $fresh->data);
        // leads_by_id intentionally has no status_name (spec §2 — name is
        // restored from tasks[].deal_stage_name downstream).
        $this->assertArrayNotHasKey('status_name', $fresh->data['leads_by_id']['42']);
        $this->assertTrue($manager->is($fresh->manager));
    }

    public function test_pulse_snapshot_unique_manager_date_kind_blocks_duplicate(): void
    {
        $manager = User::factory()->create();

        $attrs = [
            'manager_id' => $manager->id,
            'on_date' => '2026-06-22',
            'kind' => SnapKind::Plan,
            'source' => SnapSource::Manual,
            'captured_at' => now(),
            'data' => [],
        ];

        PulseSnapshot::create($attrs);

        $this->expectException(QueryException::class);
        PulseSnapshot::create($attrs);
    }

    public function test_pulse_snapshot_allows_plan_and_fact_for_same_manager_day(): void
    {
        $manager = User::factory()->create();

        $base = [
            'manager_id' => $manager->id,
            'on_date' => '2026-06-22',
            'source' => SnapSource::Auto,
            'captured_at' => now(),
            'data' => [],
        ];

        PulseSnapshot::create([...$base, 'kind' => SnapKind::Plan]);
        PulseSnapshot::create([...$base, 'kind' => SnapKind::Fact]);

        $this->assertSame(2, PulseSnapshot::where('manager_id', $manager->id)->count());
    }

    public function test_pulse_daily_status_round_trips_and_enforces_one_row_per_day(): void
    {
        $manager = User::factory()->create();

        $status = PulseDailyStatus::create([
            'manager_id' => $manager->id,
            'on_date' => '2026-06-22',
            'plan_at' => now(),
            'fact_at' => null,
            'plan_source' => SnapSource::Manual,
            'fact_source' => null,
            'plan_reminded_count' => 2,
            'fact_reminded_count' => 0,
        ]);

        $fresh = $status->fresh();

        $this->assertSame(SnapSource::Manual, $fresh->plan_source);
        $this->assertNull($fresh->fact_source);
        $this->assertNotNull($fresh->plan_at);
        $this->assertNull($fresh->fact_at);
        $this->assertSame(2, $fresh->plan_reminded_count);
        $this->assertSame('2026-06-22', $fresh->on_date->toDateString());
        $this->assertTrue($manager->is($fresh->manager));

        $this->expectException(QueryException::class);
        PulseDailyStatus::create([
            'manager_id' => $manager->id,
            'on_date' => '2026-06-22',
        ]);
    }

    public function test_pulse_skip_day_supports_team_wide_and_personal_skips(): void
    {
        $manager = User::factory()->create();

        $teamSkip = PulseSkipDay::create([
            'on_date' => '2026-06-22',
            'team_chat_id' => '-1001234567890',
            'manager_id' => null,
            'created_by' => 'Bogdan_MACRO',
        ]);

        $personalSkip = PulseSkipDay::create([
            'on_date' => '2026-06-22',
            'team_chat_id' => null,
            'manager_id' => $manager->id,
            'created_by' => 'ilyarogov',
        ]);

        $this->assertNull($teamSkip->fresh()->manager_id);
        $this->assertSame('-1001234567890', $teamSkip->fresh()->team_chat_id);
        $this->assertSame('2026-06-22', $teamSkip->fresh()->on_date->toDateString());
        $this->assertTrue($manager->is($personalSkip->fresh()->manager));
    }

    public function test_pulse_announced_event_round_trips_and_dedups_on_activity(): void
    {
        $manager = User::factory()->create();
        $deal = Deal::factory()->create();
        $activity = Activity::factory()->create();

        $event = PulseAnnouncedEvent::create([
            'activity_id' => $activity->id,
            'event_type' => AnnouncedEventType::MeetingDone,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
            'chat_id' => '-1001234567890',
            'posted_at' => now(),
        ]);

        $fresh = $event->fresh();

        $this->assertSame(AnnouncedEventType::MeetingDone, $fresh->event_type);
        $this->assertTrue($activity->is($fresh->activity));
        $this->assertTrue($deal->is($fresh->deal));
        $this->assertTrue($manager->is($fresh->manager));

        // Unique activity_id is the announcer de-dup key (spec §4).
        $this->expectException(QueryException::class);
        PulseAnnouncedEvent::create([
            'activity_id' => $activity->id,
            'event_type' => AnnouncedEventType::Success,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
            'chat_id' => '-1001234567890',
            'posted_at' => now(),
        ]);
    }

    public function test_pulse_announced_event_dedups_success_on_deal_stage_history(): void
    {
        $this->seed(PipelineSeeder::class);
        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();
        $stage = PipelineStage::where('pipeline_id', $pipeline->id)
            ->where('code', 'won')
            ->firstOrFail();

        $manager = User::factory()->create();
        $deal = Deal::factory()->create();
        $history = DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $stage->id,
            'to_stage_id' => $stage->id,
            'user_id' => $manager->id,
            'created_at' => now(),
        ]);

        // Slice 4: a Success row carries NO activity_id (it is a stage transition,
        // not a task) and dedups on deal_stage_history_id.
        $event = PulseAnnouncedEvent::create([
            'activity_id' => null,
            'deal_stage_history_id' => $history->id,
            'event_type' => AnnouncedEventType::Success,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
            'chat_id' => '-1001234567890',
            'posted_at' => now(),
        ]);

        $fresh = $event->fresh();
        $this->assertNull($fresh->activity_id);
        $this->assertSame(AnnouncedEventType::Success, $fresh->event_type);
        $this->assertTrue($history->is($fresh->dealStageHistory));

        // A second Success on the same transition violates the dsh unique key.
        $this->expectException(QueryException::class);
        PulseAnnouncedEvent::create([
            'activity_id' => null,
            'deal_stage_history_id' => $history->id,
            'event_type' => AnnouncedEventType::Success,
            'manager_id' => $manager->id,
            'deal_id' => $deal->id,
            'chat_id' => '-1001234567890',
            'posted_at' => now(),
        ]);
    }
}
