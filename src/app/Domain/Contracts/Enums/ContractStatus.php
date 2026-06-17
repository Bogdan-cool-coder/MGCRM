<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * ContractStatus — state machine for a contract lifecycle.
 *
 * Allowed transitions (matrix):
 *   draft        → submitted
 *   submitted    → in_review, rejected
 *   in_review    → needs_rework, approved, rejected
 *   needs_rework → submitted
 *   approved     → signed
 *   signed       → uploaded
 *   uploaded     → archived
 *   rejected     → (terminal)
 *   archived     → (terminal)
 *
 * Full service-layer guard is implemented in S2.2 ContractService.
 * This enum is created in S2.1 and covered by unit tests.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case NeedsRework = 'needs_rework';
    case Approved = 'approved';
    case Signed = 'signed';
    case Uploaded = 'uploaded';
    case Archived = 'archived';
    case Rejected = 'rejected';

    /**
     * Returns true when transitioning from $this to $next is permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::InReview, self::Rejected],
            self::InReview => [self::NeedsRework, self::Approved, self::Rejected],
            self::NeedsRework => [self::Submitted],
            self::Approved => [self::Signed],
            self::Signed => [self::Uploaded],
            self::Uploaded => [self::Archived],
            self::Rejected => [],
            self::Archived => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Archived], strict: true);
    }
}
