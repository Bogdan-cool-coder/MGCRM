<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Events;

use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a course is assigned to a learner (bulk-assign).
 *
 * NO listeners ship in S3.3. Downstream consumers:
 * - bot-specialist (M11): Telegram notification subscribes here.
 */
class CourseAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly CourseAssignment $assignment,
    ) {}
}
