<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services\Helpers;

use Carbon\Carbon;

/**
 * MoneyFormatter — formats kopeck integers and dates for insertion into
 * legal document text.
 *
 * All money amounts are stored as integers (kopecks / smallest currency unit).
 * Formatted output uses a space as thousands separator and comma as decimal:
 *   1234567 kopecks → «12 345,67»
 *
 * Date output uses Russian full-month notation:
 *   2026-06-13 → «13 июня 2026 г.»
 */
class MoneyFormatter
{
    /** @var array<int, string> */
    private static array $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта',
        4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября',
        10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /**
     * Format kopecks as a document-ready money string.
     *
     * @param  int  $kopecks  Integer amount in smallest units
     * @param  string  $currency  ISO-4217 code (for future locale logic)
     * @return string e.g. «12 345,67»
     */
    public static function format(int $kopecks, string $currency = 'RUB'): string
    {
        $units = intdiv(abs($kopecks), 100);
        $cents = abs($kopecks) % 100;

        // Build integer part with non-breaking space as thousands separator.
        $intFormatted = number_format($units, 0, '', "\u{00A0}");

        $result = "{$intFormatted},".str_pad((string) $cents, 2, '0', STR_PAD_LEFT);

        return $kopecks < 0 ? "-{$result}" : $result;
    }

    /**
     * Format a date string or Carbon instance as Russian legal notation.
     *
     * @param  Carbon|string|null  $date  Date value (Y-m-d or Carbon)
     * @return string e.g. «13 июня 2026 г.» or empty string if null/invalid
     */
    public static function formatDateRu(Carbon|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        try {
            $carbon = $date instanceof Carbon ? $date : Carbon::parse((string) $date);
        } catch (\Throwable) {
            return (string) $date;
        }

        $month = self::$months[(int) $carbon->format('n')] ?? '';

        return $carbon->format('j').' '.$month.' '.$carbon->format('Y').' г.';
    }
}
