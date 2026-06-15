<?php

declare(strict_types=1);

namespace App\Domain\Automation\Exceptions;

use RuntimeException;

/**
 * Thrown by AutomationTestService::dryRun() when an inline trigger
 * (on_enter_stage / on_create) is simulated without a concrete target deal.
 *
 * Inline triggers fire from a domain event, not from a DB scan — there is no
 * "matched set" to preview. The caller must pin a specific deal (the P4 endpoint
 * surfaces this as a 422 telling the admin to pick one). Carries the offending
 * trigger kind so the message / API layer can name it.
 */
final class DryRunTargetRequiredException extends RuntimeException
{
    public function __construct(public readonly string $triggerKind)
    {
        parent::__construct(
            "Dry-run for the inline trigger '{$triggerKind}' requires a concrete target deal — pick one to preview.",
        );
    }
}
