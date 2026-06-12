<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity gets a (new) responsible assignee. Notification
 * groundwork (Q5) — no listeners in S1.6.
 */
class ActivityAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Activity $activity,
        public readonly ?int $previousResponsibleId,
    ) {}
}
