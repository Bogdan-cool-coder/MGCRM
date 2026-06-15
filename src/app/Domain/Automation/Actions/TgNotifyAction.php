<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Jobs\SendAutomationTelegramJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Support\MessageFormatter;
use App\Domain\Automation\Support\RecipientResolver;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;

/**
 * tg_notify — send a Telegram message about the deal.
 *
 * Network action: execute() resolves the recipient + message and returns a
 * `queued` result carrying a job factory; it never calls Telegram inline. The
 * dispatcher parks the run as `queued` and dispatches the job, which sends and
 * finalizes the run. Reuses Notification\TelegramNotifier (the one Bot API
 * entry point) inside the job.
 *
 * config: { recipient: "owner"|"user_id:N"|"chat_id:N", message: string }
 */
final class TgNotifyAction implements ActionHandler
{
    public function kind(): ActionKind
    {
        return ActionKind::TgNotify;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $owner = $this->owner($target);
        [$chatId, $resolution] = $this->resolveChat($config, $target, $owner);

        if ($chatId === null) {
            return ActionResult::skipped($resolution);
        }

        $message = MessageFormatter::format($config['message'] ?? '', $target, $owner);
        if ($message === '') {
            return ActionResult::skipped('Empty message — nothing to send.');
        }

        // Defer the actual send; the dispatcher parks the run as `queued` and
        // fires this job with the run id.
        return ActionResult::queued(
            "Telegram message queued to chat {$chatId}",
            ['chat_id' => $chatId, 'message' => $message],
            fn (int $runId): SendAutomationTelegramJob => new SendAutomationTelegramJob($runId, $chatId, $message),
        );
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $owner = $this->owner($target);
        [$chatId, $resolution] = $this->resolveChat($config, $target, $owner);
        $message = MessageFormatter::format($config['message'] ?? '', $target, $owner);

        if ($chatId === null) {
            return ActionPreview::wont($resolution, ['message' => $message]);
        }

        if ($message === '') {
            return ActionPreview::wont('Empty message — nothing to send.');
        }

        return ActionPreview::will("Would send Telegram message to chat {$chatId}", [
            'chat_id' => $chatId,
            'message' => $message,
        ]);
    }

    /**
     * Resolve the destination chat id (string) or null + a reason.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string|null, 1: string}
     */
    private function resolveChat(array $config, Deal $target, ?User $owner): array
    {
        [$kind, $value] = RecipientResolver::telegram($config['recipient'] ?? 'owner', $target, $owner);

        if ($kind === 'none' || $value === null) {
            return [null, 'No recipient resolved.'];
        }

        if ($kind === 'chat_id') {
            return [(string) $value, 'ok'];
        }

        // user_id → resolve the user's linked telegram chat id.
        $user = $value === ($owner?->id) ? $owner : User::find($value);
        if ($user === null) {
            return [null, "User {$value} not found."];
        }

        $chatId = SendAutomationTelegramJob::chatIdForUser($user);
        if ($chatId === null) {
            return [null, "User {$value} has no linked Telegram."];
        }

        return [$chatId, 'ok'];
    }

    private function owner(Deal $target): ?User
    {
        return $target->owner_user_id !== null ? User::find($target->owner_user_id) : null;
    }
}
