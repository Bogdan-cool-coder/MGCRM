<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Jobs;

use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\DispatchAutomationWebhookJob;
use App\Domain\Automation\Jobs\SendAutomationTelegramJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Automation\Support\SsrfGuard;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AutomationNetworkJobsTest extends TestCase
{
    use RefreshDatabase;

    private function queuedRun(): AutomationRun
    {
        $automation = PipelineAutomation::factory()->create();
        $run = (new AutomationEngine)->claimRunSlot($automation, AutomationTargetType::Deal, 1, now());
        $run->update(['status' => RunStatus::Queued]);

        return $run->fresh();
    }

    // ---- Telegram job ----

    public function test_telegram_job_sends_and_marks_success(): void
    {
        $run = $this->queuedRun();

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendToChat')->once()->with('555', 'hello')->andReturn(1);

        (new SendAutomationTelegramJob($run->id, '555', 'hello'))->handle($notifier, new AutomationEngine);

        $this->assertSame(RunStatus::Success, $run->fresh()->status);
    }

    public function test_telegram_job_is_idempotent_when_run_not_queued(): void
    {
        $run = $this->queuedRun();
        $run->update(['status' => RunStatus::Success]); // already resolved

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendToChat');

        (new SendAutomationTelegramJob($run->id, '555', 'hi'))->handle($notifier, new AutomationEngine);

        $this->assertSame(RunStatus::Success, $run->fresh()->status);
    }

    // ---- Webhook job ----

    public function test_webhook_job_posts_and_marks_success(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $run = $this->queuedRun();

        $guard = $this->safeGuard();
        (new DispatchAutomationWebhookJob($run->id, 'https://hooks.example.com/x', ['a' => 1]))
            ->handle($guard, new AutomationEngine);

        $this->assertSame(RunStatus::Success, $run->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.com/x' && $request['a'] === 1);
    }

    public function test_webhook_job_marks_failed_on_4xx(): void
    {
        Http::fake(['*' => Http::response('nope', 422)]);
        $run = $this->queuedRun();

        (new DispatchAutomationWebhookJob($run->id, 'https://hooks.example.com/x', []))
            ->handle($this->safeGuard(), new AutomationEngine);

        $fresh = $run->fresh();
        $this->assertSame(RunStatus::Failed, $fresh->status);
        // failed releases the idempotency slot for a retry.
        $this->assertNull($fresh->trigger_event_ts);
    }

    public function test_webhook_job_marks_failed_when_blocked_by_guard(): void
    {
        Http::fake();
        $run = $this->queuedRun();

        // A guard that resolves the host to a private IP.
        $guard = new class extends SsrfGuard
        {
            protected function resolve(string $host): array
            {
                return ['10.0.0.1'];
            }
        };

        (new DispatchAutomationWebhookJob($run->id, 'https://internal.example.com/x', []))
            ->handle($guard, new AutomationEngine);

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        Http::assertNothingSent();
    }

    private function safeGuard(): SsrfGuard
    {
        return new class extends SsrfGuard
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
    }
}
