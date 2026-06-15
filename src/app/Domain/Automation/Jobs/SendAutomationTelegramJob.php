<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Throwable;

/**
 * SendAutomationTelegramJob — deferred delivery of a tg_notify action.
 *
 * The inline executor / dispatcher must not block the web request on Telegram
 * IO, so TgNotifyAction parks its AutomationRun as `queued` and hands the actual
 * send to this job. It resolves the recipient chat id, sends, and finalizes the
 * run to `success` / `failed` via AutomationEngine (which releases the
 * idempotency slot on failure so a cron-triggered run can retry).
 *
 * Concurrency: handle() bails if the run is no longer `queued` (another worker /
 * manual retry already resolved it), so the send happens at most once per slot.
 * tries=3 with backoff covers transient Bot API 5xx.
 */
class SendAutomationTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  int  $runId  the parked AutomationRun (status=queued)
     * @param  string  $chatId  resolved destination chat id
     * @param  string  $text  already-formatted message
     */
    public function __construct(
        private readonly int $runId,
        private readonly string $chatId,
        private readonly string $text,
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramNotifier $notifier, AutomationEngine $engine): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run === null) {
            return;
        }

        // Bail if already resolved (idempotent — another worker or manual retry).
        if ($run->status !== RunStatus::Queued) {
            return;
        }

        try {
            $notifier->sendToChat($this->chatId, $this->text);
            $engine->finalize($run, RunStatus::Success, [
                'summary' => 'Telegram message sent',
                'chat_id' => $this->chatId,
            ]);
        } catch (TelegramException $e) {
            // 4xx (blocked bot / bad chat) — terminal, no point retrying.
            Log::warning('SendAutomationTelegramJob: Telegram rejected the message', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $engine->finalize($run, RunStatus::Failed, null, $this->trim($e->getMessage()));
        } catch (Throwable $e) {
            // Transient — let the queue retry; only finalize on the final attempt.
            if ($this->attempts() >= $this->tries) {
                $engine->finalize($run, RunStatus::Failed, null, $this->trim($e->getMessage()));

                return;
            }

            throw $e;
        }
    }

    /**
     * Mark the run failed when all retries are exhausted (timeout / killed).
     */
    public function failed(Throwable $e): void
    {
        $run = AutomationRun::find($this->runId);

        if ($run !== null && $run->status === RunStatus::Queued) {
            app(AutomationEngine::class)->finalize($run, RunStatus::Failed, null, $this->trim($e->getMessage()));
        }
    }

    private function trim(string $message): string
    {
        return mb_substr($message, 0, 2000);
    }

    /**
     * Convenience used by tests / callers needing the linked-user check before
     * dispatch (mirrors SendTelegramDmJob's silent-skip when unlinked).
     */
    public static function chatIdForUser(User $user): ?string
    {
        $chatId = $user->telegram_user_id;

        return ($chatId === null || $chatId === '') ? null : (string) $chatId;
    }
}
