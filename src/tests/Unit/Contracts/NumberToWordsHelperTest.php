<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Services\Helpers\NumberToWordsHelper;
use Tests\TestCase;

/**
 * Tests for NumberToWordsHelper::toWords().
 *
 * No DB access — pure logic tests (no RefreshDatabase needed).
 */
class NumberToWordsHelperTest extends TestCase
{
    public function test_ruble_kopecks_to_words(): void
    {
        // 12345 kopecks = 123 ruble 45 kopecks
        $result = NumberToWordsHelper::toWords(12345, 'RUB');

        $this->assertStringContainsString('сто двадцать три', $result);
        $this->assertStringContainsString('рубля', $result);
        $this->assertStringContainsString('сорок пять', $result);
        $this->assertStringContainsString('копе', $result); // копеек / копейки
    }

    public function test_zero_kopecks(): void
    {
        $result = NumberToWordsHelper::toWords(0, 'RUB');

        $this->assertStringContainsString('ноль', $result);
        $this->assertStringContainsString('рублей', $result);
    }

    public function test_large_amount_contains_million(): void
    {
        // 100_000_00 kopecks = 100 000 rubles
        $result = NumberToWordsHelper::toWords(10_000_000, 'RUB');

        $this->assertStringContainsString('сто тысяч', $result);
    }

    public function test_tenge_currency_contains_tenge(): void
    {
        // 12300 kopecks = 123 tenge
        $result = NumberToWordsHelper::toWords(12300, 'KZT');

        $this->assertStringContainsString('тенге', $result);
        $this->assertStringContainsString('сто двадцать три', $result);
    }

    public function test_usd_currency_contains_dollars(): void
    {
        $result = NumberToWordsHelper::toWords(10000, 'USD');

        $this->assertStringContainsString('доллар', $result);
        $this->assertStringContainsString('сто', $result);
    }

    public function test_uzs_currency_contains_sumov(): void
    {
        $result = NumberToWordsHelper::toWords(500000, 'UZS');

        $this->assertStringContainsString('сум', $result);
        $this->assertStringContainsString('пять тысяч', $result);
    }

    public function test_one_ruble_singular(): void
    {
        // 100 kopecks = 1 ruble
        $result = NumberToWordsHelper::toWords(100, 'RUB');

        $this->assertStringContainsString('рубль', $result);
    }
}
