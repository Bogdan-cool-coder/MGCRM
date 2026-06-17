<?php

declare(strict_types=1);

namespace App\Domain\Activity\Enums;

/**
 * ActivityPriority — Task v2 (MVP) priority levels (low/normal/high/critical).
 * Stored as the string value in the DB; cast to this enum on the model.
 */
enum ActivityPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
