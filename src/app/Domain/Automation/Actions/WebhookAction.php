<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Exceptions\SsrfBlockedException;
use App\Domain\Automation\Jobs\DispatchAutomationWebhookJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Support\SsrfGuard;
use App\Domain\Sales\Models\Deal;

/**
 * webhook — POST a JSON payload to an external URL.
 *
 * Network action: execute() validates the URL with SsrfGuard up-front (so a
 * blocked target is a `skipped` run, not a parked job that fails later) and then
 * defers the POST to DispatchAutomationWebhookJob. The job re-checks the guard
 * immediately before sending (the host could re-resolve) and finalizes the run.
 *
 * MVP is a plain JSON POST behind the SSRF guard; signature/retry-policy infra
 * is owned by integration-specialist.
 *
 * config: { url: string }   (store the URL only — admin-restricted in the API)
 */
final class WebhookAction implements ActionHandler
{
    public function __construct(
        private readonly SsrfGuard $guard,
    ) {}

    public function kind(): ActionKind
    {
        return ActionKind::Webhook;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $url = isset($config['url']) ? (string) $config['url'] : '';
        if ($url === '') {
            return ActionResult::skipped('Webhook url is not set.');
        }

        // Fail fast: reject a blocked destination synchronously so it is a clear
        // `skipped` (config error) rather than a queued job that fails on send.
        try {
            $this->guard->assertSafe($url);
        } catch (SsrfBlockedException $e) {
            return ActionResult::skipped("Webhook URL blocked: {$e->getMessage()}", ['url' => $url]);
        }

        $payload = $this->payload($automation, $target);

        return ActionResult::queued(
            "Webhook POST queued to {$url}",
            ['url' => $url],
            fn (int $runId): DispatchAutomationWebhookJob => new DispatchAutomationWebhookJob($runId, $url, $payload),
        );
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $url = isset($config['url']) ? (string) $config['url'] : '';
        if ($url === '') {
            return ActionPreview::wont('Webhook url is not set.');
        }

        try {
            $this->guard->assertSafe($url);
        } catch (SsrfBlockedException $e) {
            return ActionPreview::wont("Webhook URL blocked: {$e->getMessage()}", ['url' => $url]);
        }

        return ActionPreview::will("Would POST to {$url}", [
            'webhook' => [
                'url' => $url,
                'payload' => $this->payload($automation, $target),
            ],
        ]);
    }

    /**
     * Stable webhook body. Mirrors the old project's payload shape.
     *
     * @return array<string, mixed>
     */
    private function payload(PipelineAutomation $automation, Deal $target): array
    {
        return [
            'event' => 'automation_fired',
            'automation_id' => $automation->id,
            'automation_name' => $automation->name,
            'trigger_kind' => $automation->trigger_kind->value,
            'target_type' => 'deal',
            'target_id' => $target->id,
            'owner_user_id' => $target->owner_user_id,
        ];
    }
}
