<?php

declare(strict_types=1);

namespace App\Domain\Log\Enums;

/**
 * LogSubjectType — the whitelist of entities a polymorphic entity-log row can
 * describe (deal | company | contact). DEALS 2.0 scope: the same three subjects
 * the Activity domain already targets (no Lead — a lead is a deal).
 *
 * Polymorphism is implemented WITHOUT FK (subject_type string + subject_id int),
 * mirroring Activity (target_type/target_id) and CrmFile (owner_entity_*).
 * Extending the whitelist (e.g. contract, fin_invoice) needs no migration —
 * just a new case + a visibility branch in EntityLogPolicy.
 */
enum LogSubjectType: string
{
    case Deal = 'deal';
    case Company = 'company';
    case Contact = 'contact';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
