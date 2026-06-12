<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * Status of the automated AI template review (S2.3).
 *
 * Lifecycle: pending → checking → checked | failed.
 *
 * pending  — version just created; job not yet picked up by worker.
 * checking — job is running (AI + Gotenberg in progress).
 * checked  — job finished (may have remarks or be remark-free; ai_remarks=[] is clean).
 * failed   — job threw; ai_remarks contains a system_error entry.
 *
 * null ai_remarks  → check not yet run.
 * []  ai_remarks   → checked and clean.
 */
enum AiCheckStatus: string
{
    case Pending = 'pending';
    case Checking = 'checking';
    case Checked = 'checked';
    case Failed = 'failed';
}
