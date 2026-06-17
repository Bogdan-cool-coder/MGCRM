<?php

declare(strict_types=1);

namespace App\Domain\Activity\Enums;

/**
 * ActivityStatus — the Task v2 (MVP) status machine.
 *
 * Transitions (S1.6 plan E3):
 *   new        → in_progress | rejected
 *   in_progress→ done | rejected | new
 *   done       → in_progress         (= reopen)
 *   rejected   → new | in_progress
 *
 * canTransitionTo() is a pure method (testable without a DB). The actual
 * transition guard lives in ActivityService::changeStatus(), never in a
 * controller or the model (ARCHITECTURE.md §2 — status machines through a
 * service-level guard).
 */
enum ActivityStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Allowed next statuses for the current status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Rejected],
            self::InProgress => [self::Done, self::Rejected, self::New],
            self::Done => [self::InProgress],
            self::Rejected => [self::New, self::InProgress],
        };
    }

    /**
     * Whether a transition from this status to $to is legal. Same-status is a
     * no-op and always allowed (idempotent).
     */
    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return true;
        }

        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Final statuses — done/rejected are terminal work outcomes (≠ is_closed,
     * which is the separate "closed by the orderer" flag).
     */
    public function isFinal(): bool
    {
        return $this === self::Done || $this === self::Rejected;
    }
}
