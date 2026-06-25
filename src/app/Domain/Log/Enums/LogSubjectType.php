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
 * just a new case + a `view` branch on the subject's own policy (read access to
 * a subject's log is delegated to that subject's policy in EntityLogController;
 * there is no separate Log policy).
 */
enum LogSubjectType: string
{
    case Deal = 'deal';
    case Company = 'company';
    case Contact = 'contact';

    /**
     * System — not a record subject but an admin/configuration audit anchor.
     * Used for access-control changes (role-permission grants, visibility-config
     * edits) where the "subject" is the system itself; subject_id carries the
     * acting admin's user id. These rows are written by the Access Control
     * services and are NOT surfaced on any entity timeline.
     */
    case System = 'system';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
