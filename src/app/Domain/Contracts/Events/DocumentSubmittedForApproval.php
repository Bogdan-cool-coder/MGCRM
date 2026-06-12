<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Events;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;

/**
 * Dispatched when a document enters a new approval stage:
 *   - on initial submit (stage 1)
 *   - on advancing to stage N+1 after quorum reached on stage N
 *   - on resubmit after needs_rework (stage 1 again with attempt++)
 *
 * S2.9 (bot-specialist) registers a listener to send Telegram notifications.
 */
class DocumentSubmittedForApproval
{
    /**
     * @param  array<string, mixed>  $stage  {order, name, user_ids[], min_required}
     */
    public function __construct(
        public readonly Document $document,
        public readonly ApprovalRoute $route,
        public readonly array $stage,
        public readonly int $submittedBy,
        public readonly int $attempt,
    ) {}
}
