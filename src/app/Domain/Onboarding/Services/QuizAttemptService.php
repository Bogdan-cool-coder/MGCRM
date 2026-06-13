<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;

/**
 * QuizAttemptService — manages quiz attempt lifecycle.
 *
 * S3.2 scope: start (with attempt_number via lockForUpdate + idempotency).
 * S3.4 will add submit (scoring + assignment completion check).
 *
 * attempt_number pattern: identical to ContractNumberingService — lock rows,
 * compute MAX in PHP, increment. PG: lockForUpdate takes row-level lock.
 * SQLite :memory: (tests): lockForUpdate is a no-op on single connection but
 * functionally correct.
 */
class QuizAttemptService
{
    /**
     * Start a new quiz attempt, or return the existing open attempt (idempotent).
     *
     * Idempotency rule: if there is an open attempt (finished_at IS NULL) for
     * (user, quiz) — return it without creating a new one.
     *
     * attempt_number: MAX+1 across ALL attempts (open + closed) for (user, quiz),
     * computed inside a lockForUpdate transaction.
     */
    public function start(Quiz $quiz, User $user): QuizAttempt
    {
        return DB::transaction(function () use ($quiz, $user): QuizAttempt {
            // Idempotency: return open attempt if one already exists
            $open = QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $user->id)
                ->whereNull('finished_at')
                ->first();

            if ($open !== null) {
                return $open;
            }

            // lockForUpdate — same pattern as ContractNumberingService
            // PG: does not allow FOR UPDATE with aggregates, so lock rows first,
            // then compute MAX in PHP.
            $rows = QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get(['attempt_number']);

            $maxNumber = $rows->max('attempt_number');
            $nextNumber = ($maxNumber === null ? 0 : $maxNumber) + 1;

            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'assignment_id' => null, // S3.4 fills this
                'attempt_number' => $nextNumber,
                'answers' => [],
                'started_at' => now(),
            ]);
        });
    }
}
