<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Exceptions\SsrfBlockedException;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Automation\Support\SsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DispatchAutomationWebhookJob — deferred delivery of a webhook action.
 *
 * The dispatcher parks the run as `queued` and hands the POST here so the web
 * request never blocks on outbound IO. The SSRF guard is re-checked inside the
 * job (the URL could resolve differently than at validation time, and the guard
 * is the one security boundary that must run immediately before the request).
 *
 * A non-2xx response or a transport error finalizes the run as `failed`; a 2xx
 * as `success`. Idempotent: bails when the run is no longer `queued`. The full
 * signature / retry-policy infra is integration-specialist's; MVP is a plain
 * JSON POST behind the guard with a configurable timeout.
 */
class DispatchAutomationWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly int $runId,
        private readonly string $url,
        private readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(SsrfGuard $guard, AutomationEngine $engine): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run === null || $run->status !== RunStatus::Queued) {
            return;
        }

        try {
            $guard->assertSafe($this->url);
        } catch (SsrfBlockedException $e) {
            Log::warning('DispatchAutomationWebhookJob: blocked by SSRF guard', [
                'run_id' => $this->runId,
                'reason' => $e->getMessage(),
            ]);
            $engine->finalize($run, RunStatus::Failed, null, $e->getMessage());

            return;
        }

        try {
            $timeout = (int) config('automation.webhook.timeout', 10);

            // No redirect following: a 30x to an internal URL would bypass the
            // guard we just ran on the original host.
            $response = Http::timeout($timeout)
                ->withoutRedirecting()
                ->acceptJson()
                ->post($this->url, $this->payload);

            $status = $response->status();
            $data = [
                'summary' => "Webhook POST {$this->url} → {$status}",
                'url' => $this->url,
                'status_code' => $status,
                'response_preview' => mb_substr($response->body(), 0, 256),
            ];

            if ($status >= 400) {
                $engine->finalize($run, RunStatus::Failed, $data, "Webhook returned status {$status}");

                return;
            }

            $engine->finalize($run, RunStatus::Success, $data);
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $engine->finalize($run, RunStatus::Failed, null, mb_substr($e->getMessage(), 0, 2000));

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run !== null && $run->status === RunStatus::Queued) {
            app(AutomationEngine::class)->finalize($run, RunStatus::Failed, null, mb_substr($e->getMessage(), 0, 2000));
        }
    }
}
