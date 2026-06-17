<?php

declare(strict_types=1);

namespace Database\Factories\Activity;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'kind' => ActivityType::Task->value,
            'target_type' => null,
            'target_id' => null,
            'title' => $this->faker->sentence(4),
            'body' => null,
            'due_at' => now()->addDays(2),
            'completed_at' => null,
            'completed_by_id' => null,
            'responsible_id' => fn () => User::factory(),
            'created_by_id' => fn () => User::factory(),
            'priority' => ActivityPriority::Normal->value,
            'status' => ActivityStatus::New->value,
            'is_closed' => false,
            'progress_pct' => 0,
            'result_text' => null,
            'is_pinned' => false,
            'is_first_time_meeting' => false,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => false,
            'ftm_report_url' => null,
            'meeting_report_json' => null,
            'department_id' => null,
        ];
    }

    public function call(): static
    {
        return $this->state(['kind' => ActivityType::Call->value]);
    }

    public function meeting(): static
    {
        return $this->state(['kind' => ActivityType::Meeting->value]);
    }

    public function task(): static
    {
        return $this->state(['kind' => ActivityType::Task->value]);
    }

    public function note(): static
    {
        return $this->state(['kind' => ActivityType::Note->value, 'due_at' => null]);
    }

    public function overdue(): static
    {
        return $this->state([
            'due_at' => now()->subDays(2),
            'is_closed' => false,
            'status' => ActivityStatus::InProgress->value,
        ]);
    }

    public function completed(?User $by = null): static
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ActivityStatus::Done->value,
            'completed_at' => now(),
            'completed_by_id' => $by?->id ?? $attrs['responsible_id'] ?? null,
            'progress_pct' => 100,
        ]);
    }

    public function standalone(): static
    {
        return $this->state(['target_type' => null, 'target_id' => null]);
    }

    public function forDeal(Deal $deal): static
    {
        return $this->state([
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'department_id' => $deal->department_id,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state([
            'target_type' => ActivityTargetType::Company->value,
            'target_id' => $company->id,
            'department_id' => $company->department_id,
        ]);
    }

    public function responsibleOf(User $user): static
    {
        return $this->state([
            'responsible_id' => $user->id,
            'department_id' => $user->department_id,
        ]);
    }

    public function createdByUser(User $user): static
    {
        return $this->state(['created_by_id' => $user->id]);
    }
}
