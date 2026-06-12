<?php

declare(strict_types=1);

namespace App\Domain\Activity\Events;

use App\Domain\Activity\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an activity is created. Part of the S1.6 "Notification groundwork"
 * (Q5): a stable contract that the future Notification domain subscribes to.
 * NO listeners ship in S1.6 — nothing is pushed (no in-app/email/TG).
 */
class ActivityCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Activity $activity,
    ) {}
}
