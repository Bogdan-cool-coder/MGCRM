<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\PlanTarget;
use App\Domain\Sales\Models\PlanTargetAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanTargetAudit>
 */
class PlanTargetAuditFactory extends Factory
{
    protected $model = PlanTargetAudit::class;

    public function definition(): array
    {
        return [
            'plan_target_id' => PlanTarget::factory(),
            'user_id' => User::factory(),
            'field' => 'value_kopecks',
            'old_value' => null,
            'new_value' => '1000000',
        ];
    }
}
