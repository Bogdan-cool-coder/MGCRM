<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Events;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\Document;

/**
 * Dispatched after every approver vote (approved/rejected/needs_rework).
 * Also dispatched when an approved vote doesn't reach quorum yet
 * (newDocumentStatus = InReview in that case).
 *
 * S2.9 (bot-specialist) registers a listener to send Telegram notifications.
 */
class ApprovalDecisionMade
{
    public function __construct(
        public readonly Document $document,
        public readonly Approval $approval,
        public readonly ApprovalDecision $decision,
        public readonly ContractStatus $newDocumentStatus,
    ) {}
}
