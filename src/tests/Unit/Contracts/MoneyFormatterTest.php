<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Services\Helpers\MoneyFormatter;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests for MoneyFormatter::format() and formatDateRu().
 *
 * Pure logic tests — no DB access.
 */
class MoneyFormatterTest extends TestCase
{
    public function test_formats_kopecks_with_space_separator(): void
    {
        // 1_234_567 kopecks = 12 345.67
        $result = MoneyFormatter::format(1_234_567);

        // Should contain «12 345,67» with non-breaking space and comma decimal
        $this->assertStringContainsString('12', $result);
        $this->assertStringContainsString('345', $result);
        $this->assertStringContainsString(',67', $result);
    }

    public function test_formats_zero(): void
    {
        $result = MoneyFormatter::format(0);

        $this->assertSame('0,00', $result);
    }

    public function test_formats_exactly_100_kopecks_as_one_unit(): void
    {
        $result = MoneyFormatter::format(100);

        $this->assertSame('1,00', $result);
    }

    public function test_formats_large_amount(): void
    {
        // 100_000_000 kopecks = 1 000 000.00
        $result = MoneyFormatter::format(100_000_000);

        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('000', $result);
        $this->assertStringContainsString(',00', $result);
    }

    public function test_formats_date_ru_from_string(): void
    {
        $result = MoneyFormatter::formatDateRu('2026-06-13');

        $this->assertSame('13 июня 2026 г.', $result);
    }

    public function test_formats_date_ru_from_carbon(): void
    {
        $result = MoneyFormatter::formatDateRu(Carbon::parse('2026-01-01'));

        $this->assertSame('1 января 2026 г.', $result);
    }

    public function test_formats_date_ru_null_returns_empty(): void
    {
        $result = MoneyFormatter::formatDateRu(null);

        $this->assertSame('', $result);
    }

    public function test_formats_date_all_months(): void
    {
        $expected = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];

        foreach ($expected as $month => $name) {
            $result = MoneyFormatter::formatDateRu("2026-{$month}-15");
            $this->assertStringContainsString($name, $result, "Month {$month} should contain {$name}");
        }
    }
}
