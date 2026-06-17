<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use PHPUnit\Framework\TestCase;

class ActivityStatusMachineTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(ActivityStatus::New->canTransitionTo(ActivityStatus::InProgress));
        $this->assertTrue(ActivityStatus::New->canTransitionTo(ActivityStatus::Rejected));
        $this->assertTrue(ActivityStatus::InProgress->canTransitionTo(ActivityStatus::Done));
        $this->assertTrue(ActivityStatus::InProgress->canTransitionTo(ActivityStatus::Rejected));
        $this->assertTrue(ActivityStatus::InProgress->canTransitionTo(ActivityStatus::New));
        $this->assertTrue(ActivityStatus::Done->canTransitionTo(ActivityStatus::InProgress));
        $this->assertTrue(ActivityStatus::Rejected->canTransitionTo(ActivityStatus::New));
        $this->assertTrue(ActivityStatus::Rejected->canTransitionTo(ActivityStatus::InProgress));
    }

    public function test_same_status_is_a_noop_and_allowed(): void
    {
        foreach (ActivityStatus::cases() as $status) {
            $this->assertTrue($status->canTransitionTo($status));
        }
    }

    public function test_forbidden_transitions(): void
    {
        // new cannot jump straight to done
        $this->assertFalse(ActivityStatus::New->canTransitionTo(ActivityStatus::Done));
        // done cannot go to rejected or new directly
        $this->assertFalse(ActivityStatus::Done->canTransitionTo(ActivityStatus::Rejected));
        $this->assertFalse(ActivityStatus::Done->canTransitionTo(ActivityStatus::New));
        // rejected cannot go to done
        $this->assertFalse(ActivityStatus::Rejected->canTransitionTo(ActivityStatus::Done));
    }

    public function test_is_final(): void
    {
        $this->assertTrue(ActivityStatus::Done->isFinal());
        $this->assertTrue(ActivityStatus::Rejected->isFinal());
        $this->assertFalse(ActivityStatus::New->isFinal());
        $this->assertFalse(ActivityStatus::InProgress->isFinal());
    }

    public function test_values_lists_all_cases(): void
    {
        $this->assertSame(['new', 'in_progress', 'done', 'rejected'], ActivityStatus::values());
    }
}
