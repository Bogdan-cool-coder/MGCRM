<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for ActivityService::keyActionDatesForDeal — the derived
 * last_presentation / last_touch / last_event dates feeding the deal-card header.
 */
class DealKeyActionDatesTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityService::class);
    }

    private function completed(Deal $deal, ActivityType $kind, \DateTimeInterface $at): Activity
    {
        return Activity::factory()->create([
            'kind' => $kind->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'status' => ActivityStatus::Done->value,
            'completed_at' => $at,
        ]);
    }

    public function test_all_null_when_no_completed_activities(): void
    {
        $deal = Deal::factory()->create();

        $dates = $this->service->keyActionDatesForDeal((int) $deal->id);

        $this->assertNull($dates['last_presentation_at']);
        $this->assertNull($dates['last_touch_at']);
        $this->assertNull($dates['last_event_at']);
    }

    public function test_picks_latest_per_bucket(): void
    {
        $deal = Deal::factory()->create();

        $this->completed($deal, ActivityType::Presentation, now()->subDays(10));
        $newerPresentation = $this->completed($deal, ActivityType::Presentation, now()->subDays(3));
        $call = $this->completed($deal, ActivityType::Call, now()->subDays(2));
        $meeting = $this->completed($deal, ActivityType::Meeting, now()->subDay());

        $dates = $this->service->keyActionDatesForDeal((int) $deal->id);

        $this->assertSame($newerPresentation->completed_at->toIso8601String(), $dates['last_presentation_at']);
        // touch = call/follow_up only → the call (meeting is excluded).
        $this->assertSame($call->completed_at->toIso8601String(), $dates['last_touch_at']);
        // event = any of call/follow_up/meeting/presentation → the meeting (newest).
        $this->assertSame($meeting->completed_at->toIso8601String(), $dates['last_event_at']);
    }

    public function test_open_and_note_and_task_are_ignored(): void
    {
        $deal = Deal::factory()->create();

        // Open presentation (not done) — ignored.
        Activity::factory()->create([
            'kind' => ActivityType::Presentation->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'status' => ActivityStatus::InProgress->value,
            'completed_at' => null,
        ]);
        // A completed task/note are not events → never surface.
        $this->completed($deal, ActivityType::Task, now()->subHour());
        Activity::factory()->create([
            'kind' => ActivityType::Note->value,
            'target_type' => 'deal',
            'target_id' => $deal->id,
            'status' => ActivityStatus::Done->value,
            'completed_at' => now()->subHour(),
        ]);

        $dates = $this->service->keyActionDatesForDeal((int) $deal->id);

        $this->assertNull($dates['last_presentation_at']);
        $this->assertNull($dates['last_touch_at']);
        $this->assertNull($dates['last_event_at']);
    }

    public function test_follow_up_counts_as_touch_and_event(): void
    {
        $deal = Deal::factory()->create();
        $followUp = $this->completed($deal, ActivityType::FollowUp, now()->subDay());

        $dates = $this->service->keyActionDatesForDeal((int) $deal->id);

        $this->assertSame($followUp->completed_at->toIso8601String(), $dates['last_touch_at']);
        $this->assertSame($followUp->completed_at->toIso8601String(), $dates['last_event_at']);
        $this->assertNull($dates['last_presentation_at']);
    }

    public function test_scoped_to_the_target_deal_only(): void
    {
        $deal = Deal::factory()->create();
        $other = Deal::factory()->create();

        $this->completed($other, ActivityType::Call, now()->subDay());

        $dates = $this->service->keyActionDatesForDeal((int) $deal->id);

        $this->assertNull($dates['last_touch_at']);
        $this->assertNull($dates['last_event_at']);
    }
}
