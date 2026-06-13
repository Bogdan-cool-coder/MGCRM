<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for progress percentage calculation logic.
 * No DB — tests the math only.
 */
class ProgressCalcTest extends TestCase
{
    private function calcProgress(int $completed, int $total): int
    {
        if ($total === 0) {
            return 0;
        }

        return (int) floor($completed * 100 / $total);
    }

    public function test_progress_pct_floor_not_ceiling(): void
    {
        // 7 of 8 lessons: 87.5 → floor → 87, not 88
        $this->assertSame(87, $this->calcProgress(7, 8));

        // 2 of 7 lessons: 28.57 → floor → 28
        $this->assertSame(28, $this->calcProgress(2, 7));

        // 5 of 6 lessons: 83.33 → floor → 83
        $this->assertSame(83, $this->calcProgress(5, 6));
    }

    public function test_progress_zero_when_no_lessons(): void
    {
        $this->assertSame(0, $this->calcProgress(0, 0));
    }

    public function test_progress_100_when_all_completed(): void
    {
        $this->assertSame(100, $this->calcProgress(5, 5));
        $this->assertSame(100, $this->calcProgress(1, 1));
    }

    public function test_progress_zero_when_none_completed(): void
    {
        $this->assertSame(0, $this->calcProgress(0, 10));
    }
}
