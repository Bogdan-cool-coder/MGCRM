<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';    // Reserved — not used automatically in S3
    case Overdue = 'overdue';
    case Archived = 'archived';
}
