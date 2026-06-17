<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\ContractNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * ContractNumberingService — atomically reserves and formats a contract number.
 *
 * Format: {CITY_CODE}-{sequence_number}/{COUNTRY_CODE}
 * Example: ТШК-220/UZ
 *
 * Pattern: SELECT FOR UPDATE inside DB::transaction (same as DealMoveService).
 * Called ONLY from ContractGenerationService::generate() in S2.4 — NOT from
 * DocumentService::create(). The Document.number field is null until generation.
 *
 * Numbers are monotonically increasing. A rollback at the generation layer
 * does NOT un-reserve the number (gap in sequence is acceptable, per old behaviour).
 */
class ContractNumberingService
{
    /**
     * Reserve and return the next contract number for the given city/country pair.
     *
     * @return array{number: string, city_code: string, sequence_number: int}
     */
    public function nextNumber(string $city, string $countryCode): array
    {
        return DB::transaction(function () use ($city, $countryCode): array {
            $cityCode = $this->normalizeCityCode($city);
            $normalizedCountry = strtoupper($countryCode);

            // SELECT FOR UPDATE — prevents concurrent double-increment.
            // In SQLite :memory: this is a no-op (single connection); in PG it
            // acquires a row-level lock. Tests verify sequential correctness.
            $seq = ContractNumberSequence::query()
                ->lockForUpdate()
                ->where('city_code', $cityCode)
                ->where('country_code', $normalizedCountry)
                ->first();

            if ($seq === null) {
                // First contract for this city/country pair.
                $seq = ContractNumberSequence::create([
                    'city_code' => $cityCode,
                    'country_code' => $normalizedCountry,
                    'start_number' => 220,
                    'current_number' => 220,
                ]);
            } else {
                $seq->increment('current_number');
                $seq->refresh();
            }

            $number = "{$cityCode}-{$seq->current_number}/{$normalizedCountry}";

            return [
                'number' => $number,
                'city_code' => $cityCode,
                'sequence_number' => $seq->current_number,
            ];
        });
    }

    /**
     * Normalize a city name to a 3-character uppercase code.
     * Strips non-alphabetic characters (spaces, hyphens, digits) and takes the
     * first 3 Unicode letters in uppercase.
     *
     * Examples:
     *   "Ташкент"   → ТАШ
     *   "Алма-Ата"  → АЛМ
     *   "Нур-Султан2" → НУР
     *   "АСТ"       → АСТ
     */
    public function normalizeCityCode(string $city): string
    {
        // Remove everything except Unicode letters.
        $letters = preg_replace('/[^\p{L}]/u', '', mb_strtoupper($city)) ?? '';

        return mb_substr($letters, 0, 3);
    }
}
