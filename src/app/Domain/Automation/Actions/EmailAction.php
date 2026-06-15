<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;

/**
 * email — MVP forward-compatible no-op.
 *
 * Email delivery infrastructure (SMTP transport, templates, suppression) is
 * owned by integration-specialist and lands on the integrations sprint. Until
 * then this handler validates the config shape and records a `skipped` run that
 * explicitly says the infra is pending — it NEVER fails, so an automation
 * configured with an email action keeps working (the rest of its pipeline runs)
 * and the run history shows why nothing was sent.
 *
 * config: { recipient?: "owner"|"user_id:N", subject?, body? } — parsed but not
 * yet delivered.
 */
final class EmailAction implements ActionHandler
{
    public function kind(): ActionKind
    {
        return ActionKind::Email;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        return ActionResult::skipped(
            'Email delivery is not yet available (integration sprint, M-integration).',
            ['email_infra' => 'pending'],
        );
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        return ActionPreview::wont(
            'Email delivery is not yet available (integration sprint, M-integration).',
            ['email_infra' => 'pending'],
        );
    }
}
