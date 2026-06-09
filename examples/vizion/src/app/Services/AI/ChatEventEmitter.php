<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ChatMessageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Append-only event emitter for a single assistant ChatMessage.
 *
 * Each call to emit() inserts one ChatMessageEvent row with a monotonically
 * increasing per-message `sequence`. The (chat_message_id, sequence) unique
 * index on chat_message_events is the source of truth for ordering and the
 * dedupe guarantee against double-writes if a job retries mid-run.
 *
 * The emitter is stateless across instances — `sequence` is derived from a
 * fresh MAX(sequence) read each time. We do NOT cache the last sequence in
 * memory because:
 *   1. The job process is the sole writer for a given chat_message_id
 *      (ShouldBeUnique on ProcessChatMessageJob enforces that), so contention
 *      is not expected in practice.
 *   2. Two emitter instances for the same message would each cache their own
 *      counter and diverge — making the in-memory state a footgun.
 *
 * Concurrency model:
 *   - We rely on the unique constraint to catch races. On QueryException we
 *     re-read MAX(sequence) and retry up to 3 times before re-throwing.
 *   - We deliberately do NOT use lockForUpdate(). It would help on Postgres
 *     but is a no-op under sqlite (used by the test suite via :memory: in
 *     TestCase). Catch-and-retry on the unique constraint gives the same
 *     correctness guarantee on both backends.
 *
 * Type whitelist: emit() validates `type` against ChatMessageEvent::TYPE_*
 * constants. Adding a new type means adding a constant in ChatMessageEvent
 * AND updating ALLOWED_TYPES below — this is intentional, it forces a code
 * change on both ends so the frontend renderer is never surprised by an
 * unknown event type.
 */
class ChatEventEmitter
{
    /**
     * Whitelist of event types accepted by emit(). Mirrors the TYPE_* constants
     * on ChatMessageEvent. Any new type must be added in BOTH places.
     *
     * @var list<string>
     */
    private const ALLOWED_TYPES = [
        ChatMessageEvent::TYPE_STARTED,
        ChatMessageEvent::TYPE_THINKING,
        ChatMessageEvent::TYPE_TOOL_CALL,
        ChatMessageEvent::TYPE_TOOL_RESULT,
        ChatMessageEvent::TYPE_DRY_RUN_START,
        ChatMessageEvent::TYPE_DRY_RUN_RESULT,
        ChatMessageEvent::TYPE_RETRY,
        ChatMessageEvent::TYPE_TEXT_DELTA,
        ChatMessageEvent::TYPE_FINAL_MESSAGE,
        ChatMessageEvent::TYPE_ERROR,
        ChatMessageEvent::TYPE_WIDGET_VARIANTS,
        ChatMessageEvent::TYPE_DOCUMENT_FIELDS_PROPOSED,
    ];

    /**
     * How many times to retry on a unique-constraint collision before giving
     * up. 3 attempts is enough for the practical concurrency we expect (none)
     * while still being defensive.
     */
    private const MAX_SEQUENCE_RETRIES = 3;

    public function __construct(public readonly int $chatMessageId)
    {
    }

    /**
     * Append one event to the log. The sequence number is assigned atomically
     * — under contention, multiple emitters writing to the same message would
     * race on (chat_message_id, sequence) but the unique constraint blocks
     * duplicates and we retry with the next free sequence.
     *
     * @param  string  $type     One of ChatMessageEvent::TYPE_* (validated).
     * @param  array<string, mixed>  $payload  Arbitrary jsonb payload.
     *
     * @throws \InvalidArgumentException  When $type is not in the whitelist.
     * @throws QueryException             When the DB rejects the insert after
     *                                    MAX_SEQUENCE_RETRIES attempts.
     */
    public function emit(string $type, array $payload = []): ChatMessageEvent
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown ChatMessageEvent type '{$type}'. "
                . 'Allowed: ' . implode(', ', self::ALLOWED_TYPES) . '.'
            );
        }

        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_SEQUENCE_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($type, $payload): ChatMessageEvent {
                    // Read MAX(sequence) inside the transaction. On Postgres this
                    // does NOT lock — but the unique constraint on
                    // (chat_message_id, sequence) means a colliding insert from
                    // another writer will fail at COMMIT and we'll retry below.
                    // On sqlite, transactions serialize writes via the global
                    // write lock, so contention is impossible inside tests.
                    $next = (int) ChatMessageEvent::query()
                        ->where('chat_message_id', $this->chatMessageId)
                        ->max('sequence');

                    return ChatMessageEvent::create([
                        'chat_message_id' => $this->chatMessageId,
                        'sequence'        => $next + 1,
                        'type'            => $type,
                        'payload'         => $payload,
                    ]);
                });
            } catch (QueryException $e) {
                $lastError = $e;

                // Only retry on unique-constraint violations — other QueryExceptions
                // (FK missing, column type mismatch, etc.) are bugs, not contention.
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }

                // Brief backoff before recomputing MAX(sequence). 0 µs on the
                // first retry, 5 ms, 25 ms — keeps total wall-clock under 30ms
                // for the pathological 3-retry case.
                if ($attempt > 1) {
                    usleep(5000 * ($attempt - 1) ** 2);
                }
            }
        }

        // Exhausted retries — re-throw the last collision so the caller can
        // decide what to do (typically: log + abort the streaming step).
        throw $lastError;
    }

    /**
     * Detect a unique-constraint violation across postgres + sqlite drivers
     * (the two we actually run against). Postgres uses SQLSTATE 23505, sqlite
     * uses 23000 with the string "UNIQUE constraint failed" in the message.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23505' || $sqlState === '23000') {
            // 23000 is sqlite's catch-all integrity violation; narrow to UNIQUE.
            if ($sqlState === '23000') {
                return str_contains($e->getMessage(), 'UNIQUE');
            }

            return true;
        }

        return false;
    }
}
