<?php

declare(strict_types=1);

namespace App\Domain\Activity\Enums;

/**
 * ActivityTargetType — the whitelist of valid polymorphic targets for an
 * Activity. DEALS 2.0 narrows the old 7-type target to {deal, company}; a NULL
 * target means a standalone (personal) task.
 *
 * Polymorphism is implemented WITHOUT FK (target_type string + target_id int),
 * mirroring CrmFile (owner_entity_type/owner_entity_id). Extending the whitelist
 * later (contract/subscription in S2/CS) needs no migration — just a new case.
 */
enum ActivityTargetType: string
{
    case Deal = 'deal';
    case Company = 'company';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
