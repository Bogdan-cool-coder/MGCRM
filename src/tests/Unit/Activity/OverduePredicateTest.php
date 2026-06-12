<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use Tests\TestCase;

/**
 * Pure (no-DB) tests for the computed overdue predicate (E4): overdue ⇔ due_at
 * in the past AND not closed AND status != done.
 */
class OverduePredicateTest extends TestCase
{
    private function makeActivity(array $attrs): Activity
    {
        $activity = new Activity;
        $activity->forceFill(array_merge([
            'due_at' => now()->subDay(),
            'is_closed' => false,
            'status' => ActivityStatus::InProgress->value,
        ], $attrs));

        return $activity;
    }

    public function test_overdue_true_when_past_due_open_not_done(): void
    {
        $this->assertTrue($this->makeActivity([])->isOverdue());
    }

    public function test_not_overdue_when_due_in_future(): void
    {
        $this->assertFalse($this->makeActivity(['due_at' => now()->addDay()])->isOverdue());
    }

    public function test_not_overdue_when_no_due_at(): void
    {
        $this->assertFalse($this->makeActivity(['due_at' => null])->isOverdue());
    }

    public function test_not_overdue_when_closed(): void
    {
        $this->assertFalse($this->makeActivity(['is_closed' => true])->isOverdue());
    }

    public function test_not_overdue_when_done(): void
    {
        $this->assertFalse($this->makeActivity(['status' => ActivityStatus::Done->value])->isOverdue());
    }
}
