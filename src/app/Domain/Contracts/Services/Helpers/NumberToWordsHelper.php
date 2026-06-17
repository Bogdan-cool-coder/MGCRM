<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services\Helpers;

use morphos\Cases;
use morphos\Russian\CardinalNumeralGenerator;
use morphos\Russian\MoneySpeller;

/**
 * NumberToWordsHelper — converts a kopeck integer to a human-readable
 * currency string in Russian.
 *
 * RUB: uses morphos\Russian\MoneySpeller (natively supports rubles).
 * Other: CardinalNumeralGenerator (cardinal numeral) + manual currency suffix.
 *
 * Examples:
 *   toWords(12345, 'RUB') → «сто двадцать три рубля сорок пять копеек»
 *   toWords(12300, 'KZT') → «сто двадцать три тенге»
 *   toWords(0,     'RUB') → «ноль рублей ноль копеек»
 */
class NumberToWordsHelper
{
    /**
     * Convert kopecks to words.
     *
     * @param  int  $kopecks  Money amount in smallest units (kopecks / tiyin / etc.)
     * @param  string  $currency  ISO-4217 currency code
     */
    public static function toWords(int $kopecks, string $currency): string
    {
        $amount = $kopecks / 100.0;

        return match (strtoupper($currency)) {
            'RUB' => MoneySpeller::spell($amount, MoneySpeller::RUBLE, MoneySpeller::NORMAL_FORMAT),
            'KZT' => self::spellWithSuffix($amount, 'тенге', 'тенге', 'тенге'),
            'UZS' => self::spellWithSuffix($amount, 'сум', 'сума', 'сумов'),
            'USD' => self::spellWithSuffix($amount, 'доллар США', 'доллара США', 'долларов США'),
            'EUR' => self::spellWithSuffix($amount, 'евро', 'евро', 'евро'),
            default => self::spellWithSuffix($amount, $currency, $currency, $currency),
        };
    }

    /**
     * Build a phrase: «<words> <suffix>» using cardinal numeral + Russian pluralisation.
     *
     * @param  float  $amount  The whole-unit amount (already divided by 100)
     * @param  string  $one  Suffix for 1 (nom. sg.)
     * @param  string  $few  Suffix for 2–4
     * @param  string  $many  Suffix for 0, 5–20
     */
    private static function spellWithSuffix(float $amount, string $one, string $few, string $many): string
    {
        $whole = (int) floor($amount);
        $words = CardinalNumeralGenerator::getCase($whole, Cases::NOMINATIVE);

        $mod100 = $whole % 100;
        $mod10 = $whole % 10;

        $suffix = match (true) {
            ($mod100 >= 11 && $mod100 <= 14) => $many,
            ($mod10 === 1) => $one,
            ($mod10 >= 2 && $mod10 <= 4) => $few,
            default => $many,
        };

        return "{$words} {$suffix}";
    }
}
