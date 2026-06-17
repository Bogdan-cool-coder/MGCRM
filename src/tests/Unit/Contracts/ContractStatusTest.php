<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use PHPUnit\Framework\TestCase;

class ContractStatusTest extends TestCase
{
    public function test_can_transition_draft_to_submitted(): void
    {
        $this->assertTrue(ContractStatus::Draft->canTransitionTo(ContractStatus::Submitted));
    }

    public function test_cannot_transition_submitted_to_signed(): void
    {
        $this->assertFalse(ContractStatus::Submitted->canTransitionTo(ContractStatus::Signed));
    }

    public function test_can_transition_in_review_to_needs_rework(): void
    {
        $this->assertTrue(ContractStatus::InReview->canTransitionTo(ContractStatus::NeedsRework));
    }

    public function test_can_transition_approved_to_signed(): void
    {
        $this->assertTrue(ContractStatus::Approved->canTransitionTo(ContractStatus::Signed));
    }

    public function test_archived_is_terminal_state(): void
    {
        $this->assertTrue(ContractStatus::Archived->isTerminal());
        $this->assertEmpty(ContractStatus::Archived->allowedTransitions());
    }

    public function test_rejected_is_terminal_state(): void
    {
        $this->assertTrue(ContractStatus::Rejected->isTerminal());
        $this->assertEmpty(ContractStatus::Rejected->allowedTransitions());
    }

    public function test_needs_rework_can_return_to_submitted(): void
    {
        $this->assertTrue(ContractStatus::NeedsRework->canTransitionTo(ContractStatus::Submitted));
    }

    public function test_draft_cannot_skip_to_approved(): void
    {
        $this->assertFalse(ContractStatus::Draft->canTransitionTo(ContractStatus::Approved));
    }

    public function test_full_transition_chain(): void
    {
        $chain = [
            [ContractStatus::Draft, ContractStatus::Submitted],
            [ContractStatus::Submitted, ContractStatus::InReview],
            [ContractStatus::InReview, ContractStatus::Approved],
            [ContractStatus::Approved, ContractStatus::Signed],
            [ContractStatus::Signed, ContractStatus::Uploaded],
            [ContractStatus::Uploaded, ContractStatus::Archived],
        ];

        foreach ($chain as [$from, $to]) {
            $this->assertTrue(
                $from->canTransitionTo($to),
                "Expected {$from->value} → {$to->value} to be allowed"
            );
        }
    }
}
