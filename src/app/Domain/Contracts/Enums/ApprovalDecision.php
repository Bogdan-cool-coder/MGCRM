<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * ApprovalDecision — verdict of a single approver on a contract attempt.
 * Created in S2.1 as a stub; full ApprovalService quorum logic lands in S2.6.
 */
enum ApprovalDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsRework = 'needs_rework';
}
