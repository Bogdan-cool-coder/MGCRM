<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity changes status (including complete/reopen, which are
 * status transitions). Notification groundwork (Q5) — no listeners in S1.6.
 */
class ActivityStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Activity $activity,
        public readonly ActivityStatus $from,
        public readonly ActivityStatus $to,
    ) {}
}
