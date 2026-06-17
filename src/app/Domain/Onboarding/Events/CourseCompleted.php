<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Events;

use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a learner completes all published lessons in a course.
 *
 * NO listeners ship in S3.3. Downstream consumers:
 * - S3.6: GenerateCertificateJob subscribes here.
 * - bot-specialist (M11): Telegram notification subscribes here.
 */
class CourseCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly CourseAssignment $assignment,
    ) {}
}
