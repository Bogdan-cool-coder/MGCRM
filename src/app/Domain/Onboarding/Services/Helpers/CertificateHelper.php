<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services\Helpers;

use Carbon\Carbon;

/**
 * CertificateHelper — utility functions for certificate rendering.
 */
class CertificateHelper
{
    /** @var array<int, string> */
    private static array $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта',
        4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября',
        10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /**
     * Format a Carbon date as Russian full-month notation.
     *
     * Example: 2026-06-14 → «14 июня 2026 г.»
     */
    public static function formatDate(Carbon $date): string
    {
        $month = self::$months[(int) $date->format('n')];

        return $date->format('j').' '.$month.' '.$date->format('Y').' г.';
    }
}
