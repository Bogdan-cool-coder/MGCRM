<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Services\AI\ChatEventEmitter;
use App\Services\AI\ChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async runner for a single assistant ChatMessage turn.
 *
 * Lifecycle:
 *   1. Controller (or test) creates the user + assistant rows and dispatches
 *      this job with the assistant message id.
 *   2. Queue worker (M3 container) picks the job up off the `ai-chat` queue.
 *   3. handle() flips status pending→running, emits `started`, runs the AI
 *      via ChatService::runForJob(), and on return flips status to `done`.
 *   4. Any exception inside runForJob() is caught and turned into status=error
 *      plus a final `error` event. We do NOT re-throw — `$tries=1` means the
 *      queue worker would mark the job as failed and the message stays in
 *      `running` forever. Catching here gives a deterministic terminal state.
 *
 * Concurrency guards:
 *   - ShouldBeUnique with the assistant message id as the key blocks accidental
 *     double-dispatch (e.g. retries from controller-side bugs).
 *   - In handle() we re-read the message and bail if status is no longer
 *     `pending`, so even if the unique guard ever fails (different process
 *     instance, expired TTL) we never run the same turn twice. Idempotent.
 *
 * Why $tries=1: any retry logic worth doing (provider cascade, semantic retry
 * on dry-run failure) already lives inside ChatService / AiRetryService. A
 * blind queue-level retry would just burn another full AI turn for the same
 * problem.
 */
class ProcessChatMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * No queue-level retries. Semantic retries are inside ChatService.
     */
    public int $tries = 1;

    /**
     * Wall-clock cap matching docker-compose queue-worker --timeout=600 (M3).
     * If a single AI turn exceeds this, SIGTERM will fire and `failed()` runs.
     */
    public int $timeout = 600;

    /**
     * TTL for the ShouldBeUnique lock, in seconds. Matches $timeout so a job
     * holding the lock cannot deadlock future dispatches if it dies without
     * cleanup. We pick the max-turn duration deliberately — under that, any
     * concurrent dispatch for the same assistant_message_id is suppressed.
     */
    public int $uniqueFor = 600;

    /**
     * @param  int  $assistantMessageId
     * @param  array<string, mixed>|null  $reportContext  optional in-report
     *         quick_qa snapshot from the frontend: {primaryModel, reportId,
     *         reportTitle, columns, filters}. When present, ChatService swaps
     *         the heavy QUICK_QA_PROMPT.md catalog for the slim per-model
     *         semantic note + report snapshot. Null = legacy general quick_qa
     *         (or report_generation). Serialised straight into the job
     *         payload; defensive — frontend may omit; backend tolerates any
     *         shape and only honours it when `primaryModel` is a non-empty
     *         string (see ChatService::resolveReportContext()).
     */
    public function __construct(
        public readonly int $assistantMessageId,
        public readonly ?array $reportContext = null,
    ) {
        // Dedicated queue so AI turns don't starve smaller default-queue jobs
        // (and vice versa). The queue-worker container in docker-compose
        // listens to `ai-chat,default` so default still drains. The Queueable
        // trait already declares the $queue property — we configure it via
        // onQueue() rather than re-declaring (PHP forbids the latter).
        $this->onQueue('ai-chat');
    }

    /**
     * Unique key for ShouldBeUnique. Two dispatches with the same assistant
     * message id will be suppressed; this is exactly the idempotency we want
     * (a single message turn produces at most one job).
     */
    public function uniqueId(): string
    {
        return (string) $this->assistantMessageId;
    }

    public function handle(ChatService $chatService): void
    {
        $message = ChatMessage::with('chat')->find($this->assistantMessageId);

        if ($message === null) {
            Log::warning('ProcessChatMessageJob: assistant message vanished before handle()', [
                'assistant_message_id' => $this->assistantMessageId,
            ]);

            return;
        }

        // Idempotency guard. If a previous run already completed this message
        // (or marked it errored), do not re-run. This protects against:
        //   - duplicate dispatches racing past the ShouldBeUnique TTL
        //   - manual `queue:retry` on an already-finished job
        //   - test or admin tooling that re-dispatches by accident
        if ($message->status !== ChatMessage::STATUS_PENDING) {
            Log::info('ProcessChatMessageJob: skipping — message already past pending', [
                'assistant_message_id' => $message->id,
                'current_status'       => $message->status,
            ]);

            return;
        }

        // Flip to running BEFORE emitting `started` so any concurrent reader
        // (frontend long-poll) sees the lifecycle field move first, then the
        // event. The reverse order would make a poll catch `started` while
        // status is still `pending` — racy and confusing.
        $message->update([
            'status'     => ChatMessage::STATUS_RUNNING,
            'started_at' => now(),
            // $this->job is set by the queue worker before handle() runs. It
            // may be null when handle() is called manually in a test that
            // bypasses the queue infrastructure — in that case job_id stays
            // null, which is fine.
            'job_id'     => $this->job?->uuid(),
        ]);

        $emitter = new ChatEventEmitter($message->id);
        $emitter->emit(ChatMessageEvent::TYPE_STARTED, [
            'job_id'  => $message->job_id,
            'chat_id' => $message->chat_id,
        ]);

        try {
            $chatService->runForJob($message, $emitter, $this->reportContext);

            // runForJob() persists `content` and `metadata` and emits
            // `final_message` itself. We are only responsible for flipping
            // status to `done` and stamping finished_at.
            $message->update([
                'status'      => ChatMessage::STATUS_DONE,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->captureError($message, $emitter, $e);

            // Deliberately not re-thrown: with $tries=1 a re-throw would just
            // route the job into failed_jobs and leave us with status=error
            // already set. Suppressing keeps the queue-worker log clean.
        }
    }

    /**
     * Last-resort hook if handle() itself dies in a way the inner try/catch
     * can't observe (OOM kill, lost DB connection mid-update, SIGTERM on
     * timeout). Best-effort terminal-state marking + error event.
     *
     * Wrapped in a top-level try/catch because failed() runs in the worker
     * after the connection or process is already partially toast; we do not
     * want a secondary exception here to mask the original.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $message = ChatMessage::find($this->assistantMessageId);

            if ($message === null) {
                return;
            }

            // Don't stomp on a terminal state that handle() may have already
            // set inside its own try/catch.
            if ($message->status === ChatMessage::STATUS_DONE
                || $message->status === ChatMessage::STATUS_ERROR
                || $message->status === ChatMessage::STATUS_CANCELLED) {
                return;
            }

            $emitter = new ChatEventEmitter($message->id);
            $this->captureError($message, $emitter, $e);
        } catch (\Throwable $secondary) {
            Log::error('ProcessChatMessageJob::failed() could not record terminal state', [
                'assistant_message_id' => $this->assistantMessageId,
                'primary_error'        => $e->getMessage(),
                'secondary_error'      => $secondary->getMessage(),
            ]);
        }
    }

    /**
     * Common path for both the inner exception handler and the outer
     * failed() hook: mark the message as errored, write a final `error`
     * event, log a structured warning.
     */
    private function captureError(ChatMessage $message, ChatEventEmitter $emitter, \Throwable $e): void
    {
        $category = $this->classifyError($e);

        Log::warning('ProcessChatMessageJob: AI turn failed', [
            'assistant_message_id' => $message->id,
            'chat_id'              => $message->chat_id,
            'exception_class'      => get_class($e),
            'message'              => $e->getMessage(),
            'category'             => $category,
        ]);

        $userFacingMessage = $this->userFacingMessageFor($category);

        // The error event is best-effort. If even this insert fails (e.g. DB
        // is gone), we still want to attempt the status flip below so the
        // frontend doesn't poll forever.
        try {
            $emitter->emit(ChatMessageEvent::TYPE_ERROR, [
                'exception_class'     => get_class($e),
                'message'             => $e->getMessage(),
                // category + user_message let the frontend render a friendly
                // hint without parsing the raw provider error. `category` is
                // one of: 'context_overflow', 'rate_limit', 'timeout', 'other'.
                // See classifyError() for the patterns.
                'category'            => $category,
                'user_message'        => $userFacingMessage,
            ]);
        } catch (\Throwable $emitError) {
            Log::warning('ProcessChatMessageJob: failed to emit error event', [
                'assistant_message_id' => $message->id,
                'emit_error'           => $emitError->getMessage(),
            ]);
        }

        try {
            $message->update([
                'status'      => ChatMessage::STATUS_ERROR,
                'finished_at' => now(),
                // Surface the error class + message in metadata so the
                // frontend can render something even without polling events.
                'metadata'    => array_merge(
                    $message->metadata ?? [],
                    [
                        'error' => [
                            'exception_class' => get_class($e),
                            'message'         => $e->getMessage(),
                            'category'        => $category,
                            'user_message'    => $userFacingMessage,
                        ],
                    ]
                ),
                // Mirror chats.ai_error i18n behaviour from the old sync flow
                // so frontends that just render `content` for system/error
                // messages still get a user-facing string instead of null.
                // For known categories we surface a specific hint instead of
                // the generic "AI error" so the user knows what to do next.
                'content'     => $message->content ?: $userFacingMessage,
            ]);
        } catch (\Throwable $stateError) {
            Log::error('ProcessChatMessageJob: could not flip message to error state', [
                'assistant_message_id' => $message->id,
                'state_error'          => $stateError->getMessage(),
            ]);
        }
    }

    /**
     * Classify a Throwable into one of a small set of UX-meaningful buckets.
     * Drives both the structured `category` field on the error event/metadata
     * and the user-facing message the frontend can render verbatim.
     *
     * Buckets:
     *   - context_overflow — the prompt grew past the provider's max input
     *     (GLM error code 1261 "Prompt exceeds max length"; Anthropic
     *     "max_tokens", "context_length", "too long"). Distinct from quota /
     *     rate-limit and not actionable by retry — user must shorten the
     *     conversation or start a new chat.
     *   - rate_limit — provider quota / per-minute throttling. Already
     *     exhausted by AiRetryService's cascade by the time we see it.
     *   - timeout — provider stalled or HTTP / nginx timeout fired.
     *   - other — generic catch-all; fall back to the localized "AI error".
     */
    private function classifyError(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        // GLM Z.AI surfaces context overflow as: HTTP 400 + body
        //   {"error":{"code":"1261","message":"Prompt exceeds max length"}}
        // Anthropic uses phrases like "prompt is too long" or hits the
        // explicit `max_tokens` validator. We match liberally because the
        // exact wording is upstream-defined and varies across SDK versions.
        $overflowSignals = [
            '"code":"1261"',
            'code 1261',
            'prompt exceeds max length',
            'exceeds max length',
            'context length',
            'context_length',
            'too long',
            'prompt is too long',
            'maximum context',
            'token limit',
        ];
        foreach ($overflowSignals as $signal) {
            if (str_contains($msg, $signal)) {
                return 'context_overflow';
            }
        }

        if (str_contains($msg, 'rate limit')
            || str_contains($msg, 'rate-limit')
            || str_contains($msg, 'rate_limit')
            || str_contains($msg, 'overloaded')
            || str_contains($msg, '429')) {
            return 'rate_limit';
        }

        if (str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'curl error 28')) {
            return 'timeout';
        }

        return 'other';
    }

    /**
     * Translation key + fallback per error category. Keys live under
     * lang/{ru,en}/chats.php; if the key is missing __( ) returns the key
     * itself, which is good enough for a server log but not for a user. The
     * second argument provides a Russian fallback (the default UI locale)
     * so we always have something usable even on a fresh deploy where the
     * translation file hasn't been updated yet.
     */
    private function userFacingMessageFor(string $category): string
    {
        return match ($category) {
            'context_overflow' => __('chats.ai_error_context_overflow', [], app()->getLocale())
                ?: 'Контекст диалога слишком большой для AI. Начните новый чат или сократите сообщение.',
            'rate_limit' => __('chats.ai_error_rate_limit', [], app()->getLocale())
                ?: 'AI-сервис перегружен. Попробуйте ещё раз через минуту.',
            'timeout' => __('chats.ai_error_timeout', [], app()->getLocale())
                ?: 'AI-сервис не ответил вовремя. Попробуйте ещё раз.',
            default => __('chats.ai_error'),
        };
    }
}
