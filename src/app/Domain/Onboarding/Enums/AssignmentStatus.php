<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';    // Set by ProgressService::markFailed (SoftGate + exhausted retries)
    case Overdue = 'overdue';
    case Archived = 'archived';
}
