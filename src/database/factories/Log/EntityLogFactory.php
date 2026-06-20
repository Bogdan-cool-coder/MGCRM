<?php

declare(strict_types=1);

namespace Database\Factories\Log;

use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntityLog>
 */
class EntityLogFactory extends Factory
{
    protected $model = EntityLog::class;

    public function definition(): array
    {
        return [
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => Deal::factory(),
            'actor_id' => User::factory(),
            'action' => LogAction::DataChanged->value,
            'meta' => [],
            'created_at' => now(),
        ];
    }

    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (): array => [
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $deal->id,
        ]);
    }

    public function action(LogAction $action): static
    {
        return $this->state(fn (): array => ['action' => $action->value]);
    }
}
