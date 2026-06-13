<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Enums;

/**
 * CompletionPolicy — how course completion affects the employee's workflow.
 *
 * informational — completion grants a badge/certificate; no workflow gate.
 * soft_gate     — completion is required before the next employee status
 *                 transition (enforcement implemented in S3.3 AssignmentService).
 */
enum CompletionPolicy: string
{
    case Informational = 'informational';
    case SoftGate = 'soft_gate';
}
