<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Support\RuPlural;
use Tests\TestCase;

/**
 * RuPlural — Russian three-form pluralization (spec §7 days_str / tasks_str).
 * The teen exception (11..14 → many) is checked before the last-digit rule.
 */
class RuPluralTest extends TestCase
{
    public function test_one_form_for_last_digit_one_except_teens(): void
    {
        $this->assertSame('день', RuPlural::pick(1, 'день', 'дня', 'дней'));
        $this->assertSame('день', RuPlural::pick(21, 'день', 'дня', 'дней'));
        $this->assertSame('день', RuPlural::pick(101, 'день', 'дня', 'дней'));
    }

    public function test_few_form_for_last_digit_two_to_four(): void
    {
        $this->assertSame('дня', RuPlural::pick(2, 'день', 'дня', 'дней'));
        $this->assertSame('дня', RuPlural::pick(3, 'день', 'дня', 'дней'));
        $this->assertSame('дня', RuPlural::pick(4, 'день', 'дня', 'дней'));
        $this->assertSame('дня', RuPlural::pick(22, 'день', 'дня', 'дней'));
    }

    public function test_many_form_for_zero_and_five_plus(): void
    {
        $this->assertSame('дней', RuPlural::pick(0, 'день', 'дня', 'дней'));
        $this->assertSame('дней', RuPlural::pick(5, 'день', 'дня', 'дней'));
        $this->assertSame('дней', RuPlural::pick(20, 'день', 'дня', 'дней'));
        $this->assertSame('дней', RuPlural::pick(100, 'день', 'дня', 'дней'));
    }

    public function test_teens_are_always_many(): void
    {
        foreach ([11, 12, 13, 14, 111, 112, 113, 114] as $n) {
            $this->assertSame('дней', RuPlural::pick($n, 'день', 'дня', 'дней'), "n={$n}");
        }
    }

    public function test_days_and_tasks_full_phrases(): void
    {
        $this->assertSame('1 день', RuPlural::days(1));
        $this->assertSame('2 дня', RuPlural::days(2));
        $this->assertSame('5 дней', RuPlural::days(5));
        $this->assertSame('11 дней', RuPlural::days(11));

        $this->assertSame('1 задача', RuPlural::tasks(1));
        $this->assertSame('3 задачи', RuPlural::tasks(3));
        $this->assertSame('7 задач', RuPlural::tasks(7));
    }
}
