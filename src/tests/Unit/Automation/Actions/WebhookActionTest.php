<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\WebhookAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Jobs\DispatchAutomationWebhookJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Support\SsrfGuard;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A guard that resolves any host to a fixed IP, so tests do not hit DNS.
     */
    private function action(string $resolvedIp): WebhookAction
    {
        $guard = new class($resolvedIp) extends SsrfGuard
        {
            public function __construct(private readonly string $ip) {}

            protected function resolve(string $host): array
            {
                return [$this->ip];
            }
        };

        return new WebhookAction($guard);
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::Webhook, $this->action('8.8.8.8')->kind());
    }

    public function test_execute_queues_post_for_public_url(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action('8.8.8.8')->execute($automation, $deal, ['url' => 'https://hooks.example.com/x']);

        $this->assertSame(ActionStatus::Queued, $result->status);
        $this->assertSame('https://hooks.example.com/x', $result->data['url']);
        $this->assertInstanceOf(DispatchAutomationWebhookJob::class, ($result->deferredJobFactory)(7));
    }

    public function test_execute_blocks_private_resolved_host(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        // Host resolves to a private IP — SSRF guard must reject it as skipped.
        $result = $this->action('10.0.0.5')->execute($automation, $deal, ['url' => 'https://internal.example.com/x']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertStringContainsString('blocked', strtolower($result->summary));
    }

    public function test_execute_blocks_raw_loopback_ip(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action('8.8.8.8')->execute($automation, $deal, ['url' => 'http://127.0.0.1/internal']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_execute_skips_without_url(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action('8.8.8.8')->execute($automation, $deal, []);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_dry_run_wont_for_blocked_url(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action('8.8.8.8')->dryRun($automation, $deal, ['url' => 'http://169.254.169.254/meta']);

        $this->assertFalse($preview->wouldExecute);
    }

    public function test_dry_run_previews_payload_for_safe_url(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['name' => 'Notify CRM']);

        $preview = $this->action('8.8.8.8')->dryRun($automation, $deal, ['url' => 'https://hooks.example.com/x']);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('automation_fired', $preview->data['webhook']['payload']['event']);
        $this->assertSame($deal->id, $preview->data['webhook']['payload']['target_id']);
    }
}
