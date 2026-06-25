<?php

declare(strict_types=1);

namespace App\Domain\Log\Enums;

/**
 * LogAction — the kind of event recorded on a subject's timeline. Backed enum,
 * stored as a short string column (no migration to add a case). Mirrors the
 * documented action vocabulary in ./examples/contracts (services/audit.py
 * AUDIT_ACTIONS) but narrowed to the events MGCRM actually emits today.
 *
 * The `meta` JSON column carries the per-action detail (see EntityLogService::
 * record callers): from/to stage, the linked contact's name, the task category,
 * the changed-fields diff, a document/payment number, etc.
 *
 * Domain coverage:
 *   - created          deal created (DealService::create / createInbound)
 *   - stage_changed    deal moved stage from→to (DealMoveService::move)
 *   - contact_added    contact linked to a deal/company (DealContactService /
 *                      CompanyService::addEmployee)
 *   - meeting_held     a meeting activity completed (ActivityService::complete)
 *   - task_completed   a task-like activity completed (ActivityService::complete)
 *   - kp_sent          КП (commercial proposal) marked as sent on a deal
 *                      (DealService::markKpSent)
 *   - contract_sent    contract marked as sent on a deal (DealService::
 *                      markContractSent — manual action OR auto from a contract
 *                      Document reaching `submitted`)
 *   - data_changed     deal/company key-field diff (Deal/CompanyService::update)
 *   - contract_event   contract lifecycle (recorded ONLY if Contracts domain
 *                      exists; extension point — see EntityLogService docblock)
 *   - finance_event    finance lifecycle (Finance domain does not exist yet —
 *                      reserved extension point, never emitted today)
 */
enum LogAction: string
{
    case Created = 'created';
    case StageChanged = 'stage_changed';
    case ContactAdded = 'contact_added';
    case MeetingHeld = 'meeting_held';
    case TaskCompleted = 'task_completed';
    case KpSent = 'kp_sent';
    case ContractSent = 'contract_sent';
    case DataChanged = 'data_changed';
    case ContractEvent = 'contract_event';
    case FinanceEvent = 'finance_event';

    // ---- Access Control audit (System subject) ----
    // Admin edited a role's spatie permission set (Settings → Access Control →
    // Roles). meta: { role, added: [...], removed: [...] }.
    case PermissionChanged = 'permission_changed';
    // Admin edited a role's visibility scope (Settings → Access Control →
    // Visibility). meta: { role, from, to }.
    case VisibilityChanged = 'visibility_changed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
