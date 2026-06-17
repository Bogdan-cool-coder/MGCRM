<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * SendTelegramDmJob (S2.9) — sends a direct message to a single user (e.g. the
 * document author's verdict notification).
 *
 * Silent when the user is not linked (no telegram_user_id) — logs info and
 * returns, never fails the job. tries=3 with backoff for transient errors.
 *
 * PHP 8.5 + Queueable: queue via $this->onQueue() in the constructor — no
 * `public string $queue` property.
 */
class SendTelegramDmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly int $userId,
        private readonly string $text,
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramNotifier $notifier): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        if ($user->telegram_user_id === null || $user->telegram_user_id === '') {
            Log::info('SendTelegramDmJob: recipient has no linked Telegram, skipping', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            $notifier->sendToUser($user, $this->text);
        } catch (TelegramException $e) {
            // 4xx (e.g. user blocked the bot) — log, do not retry.
            Log::warning('SendTelegramDmJob: Telegram rejected the DM', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
