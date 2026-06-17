<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Services\ContractNumberingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractNumberingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractNumberingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContractNumberingService;
    }

    public function test_first_call_returns_start_number(): void
    {
        $result = $this->service->nextNumber('Ташкент', 'UZ');

        $this->assertSame(220, $result['sequence_number']);
        $this->assertSame('ТАШ', $result['city_code']);
        $this->assertSame('ТАШ-220/UZ', $result['number']);
    }

    public function test_second_call_returns_incremented_number(): void
    {
        $this->service->nextNumber('Ташкент', 'UZ');
        $result = $this->service->nextNumber('Ташкент', 'UZ');

        $this->assertSame(221, $result['sequence_number']);
        $this->assertSame('ТАШ-221/UZ', $result['number']);
    }

    public function test_different_city_creates_independent_sequence(): void
    {
        $tash = $this->service->nextNumber('Ташкент', 'UZ');
        $alm = $this->service->nextNumber('Алматы', 'KZ');

        // Both start at 220 (independent counters).
        $this->assertSame(220, $tash['sequence_number']);
        $this->assertSame(220, $alm['sequence_number']);
        $this->assertSame('ТАШ', $tash['city_code']);
        $this->assertSame('АЛМ', $alm['city_code']);
    }

    public function test_different_country_creates_independent_sequence(): void
    {
        $uz = $this->service->nextNumber('Ташкент', 'UZ');
        $kz = $this->service->nextNumber('Ташкент', 'KZ');

        $this->assertSame(220, $uz['sequence_number']);
        $this->assertSame(220, $kz['sequence_number']);
        $this->assertSame('ТАШ-220/UZ', $uz['number']);
        $this->assertSame('ТАШ-220/KZ', $kz['number']);
    }

    public function test_city_with_spaces_is_normalized(): void
    {
        $result = $this->service->nextNumber('Алма-Ата', 'KZ');

        $this->assertSame('АЛМ', $result['city_code']);
        $this->assertSame('АЛМ-220/KZ', $result['number']);
    }

    public function test_city_with_numbers_strips_digits(): void
    {
        // "НУР-Султан2" → strip non-letters → "НУРСУЛТАН" → first 3 = "НУР"
        $result = $this->service->nextNumber('Нур-Султан2', 'KZ');

        $this->assertSame('НУР', $result['city_code']);
    }

    public function test_city_short_name_used_as_is(): void
    {
        // "АСТ" → already 3 letters, uppercase
        $result = $this->service->nextNumber('АСТ', 'KZ');

        $this->assertSame('АСТ', $result['city_code']);
    }

    public function test_number_format_matches_pattern(): void
    {
        $result = $this->service->nextNumber('Ташкент', 'UZ');

        // Pattern: {CITY_CODE}-{number}/{COUNTRY_CODE}
        // city_code can be Cyrillic or Latin letters, 1–3 chars.
        $this->assertStringContainsString('-', $result['number']);
        $this->assertStringContainsString('/UZ', $result['number']);
        $this->assertSame('ТАШ-220/UZ', $result['number']);
    }

    public function test_sequential_calls_return_unique_numbers(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $this->service->nextNumber('Ташкент', 'UZ');
            $numbers[] = $result['number'];
        }

        $this->assertSame(count($numbers), count(array_unique($numbers)), 'All numbers should be unique');
    }

    public function test_country_code_normalized_to_uppercase(): void
    {
        $result = $this->service->nextNumber('Ташкент', 'uz');

        $this->assertSame('UZ', substr($result['number'], strrpos($result['number'], '/') + 1));
    }

    // ---- normalizeCityCode unit tests ----

    public function test_normalize_city_code_pure_latin(): void
    {
        $this->assertSame('ALM', $this->service->normalizeCityCode('Almaty'));
    }

    public function test_normalize_city_code_strips_hyphen(): void
    {
        $this->assertSame('АЛМ', $this->service->normalizeCityCode('Алма-Ата'));
    }

    public function test_normalize_city_code_strips_spaces(): void
    {
        $this->assertSame('АСТ', $this->service->normalizeCityCode('Астана'));
    }

    public function test_normalize_city_code_truncates_to_3(): void
    {
        $this->assertSame('АЛМ', $this->service->normalizeCityCode('Алматы'));
    }
}
