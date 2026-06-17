<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Enums\AssignmentStatus;
use PHPUnit\Framework\TestCase;
use ValueError;

class AssignmentStatusEnumTest extends TestCase
{
    public function test_all_six_statuses_exist(): void
    {
        $cases = AssignmentStatus::cases();
        $values = array_map(fn (AssignmentStatus $s) => $s->value, $cases);

        $this->assertContains('pending', $values);
        $this->assertContains('in_progress', $values);
        $this->assertContains('completed', $values);
        $this->assertContains('failed', $values);
        $this->assertContains('overdue', $values);
        $this->assertContains('archived', $values);
        $this->assertCount(6, $cases);
    }

    public function test_status_from_string(): void
    {
        $this->assertSame(AssignmentStatus::Pending, AssignmentStatus::from('pending'));
        $this->assertSame(AssignmentStatus::InProgress, AssignmentStatus::from('in_progress'));
        $this->assertSame(AssignmentStatus::Completed, AssignmentStatus::from('completed'));
        $this->assertSame(AssignmentStatus::Failed, AssignmentStatus::from('failed'));
        $this->assertSame(AssignmentStatus::Overdue, AssignmentStatus::from('overdue'));
        $this->assertSame(AssignmentStatus::Archived, AssignmentStatus::from('archived'));
    }

    public function test_invalid_status_throws(): void
    {
        $this->expectException(ValueError::class);

        AssignmentStatus::from('unknown_status');
    }
}
