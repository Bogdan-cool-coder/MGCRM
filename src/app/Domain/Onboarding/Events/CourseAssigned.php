<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Events;

use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a course is assigned to a learner (bulk-assign).
 *
 * INTENTIONALLY NO LISTENER IN S3.3 — this is an M11 extension point.
 *
 * Downstream consumer (registered in AppServiceProvider when implemented):
 *   bot-specialist (M11): Telegram/email notification on course assignment.
 *   automation-specialist (M11): triggers onboarding workflow automation.
 *
 * How to wire (M11): in AppServiceProvider::boot():
 *   Event::listen(CourseAssigned::class, SendCourseAssignedNotification::class);
 *
 * Until M11 the event is dispatched and silently dropped — no action, no error.
 */
class CourseAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly CourseAssignment $assignment,
    ) {}
}
