<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'attempt' => 1,
            'stage_order' => 1,
            'user_id' => User::factory(),
            'decision' => ApprovalDecision::Pending->value,
            'comment' => null,
            'decided_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'decision' => ApprovalDecision::Pending->value,
            'decided_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'decision' => ApprovalDecision::Approved->value,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'decision' => ApprovalDecision::Rejected->value,
            'comment' => 'Rejected in test.',
            'decided_at' => now(),
        ]);
    }

    public function needsRework(): static
    {
        return $this->state([
            'decision' => ApprovalDecision::NeedsRework->value,
            'comment' => 'Needs rework in test.',
            'decided_at' => now(),
        ]);
    }
}
