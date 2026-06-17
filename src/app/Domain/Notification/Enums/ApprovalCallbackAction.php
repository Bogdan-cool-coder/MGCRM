<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

use App\Domain\Contracts\Enums\ApprovalDecision;

/**
 * ApprovalCallbackAction (S2.9) — the action encoded in an inline-button
 * callback_data `apv:{action}:{documentId}`.
 *
 * Maps a bot button to a Contracts ApprovalDecision. `reject`/`rework` require a
 * comment (collected via RejectReasonConversation); `approve` does not.
 */
enum ApprovalCallbackAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Rework = 'rework';

    public function toDecision(): ApprovalDecision
    {
        return match ($this) {
            self::Approve => ApprovalDecision::Approved,
            self::Reject => ApprovalDecision::Rejected,
            self::Rework => ApprovalDecision::NeedsRework,
        };
    }

    /** True when this action requires a free-text comment before deciding. */
    public function requiresComment(): bool
    {
        return $this !== self::Approve;
    }
}
