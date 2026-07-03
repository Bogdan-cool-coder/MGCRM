<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * PlanLayer — Operative (revisable) vs Annual (fixed) planning layer (contract
 * §3.4). Both coexist independently per (metric, scope, period).
 */
enum PlanLayer: string
{
    case Operative = 'operative';
    case Annual = 'annual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
