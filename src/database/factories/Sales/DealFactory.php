<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => fn () => Pipeline::factory(),
            'stage_id' => function (array $attrs): int {
                $pipelineId = $attrs['pipeline_id'] instanceof Pipeline
                    ? $attrs['pipeline_id']->id
                    : $attrs['pipeline_id'];

                return PipelineStage::factory()->create([
                    'pipeline_id' => $pipelineId,
                    'sort_order' => 1,
                ])->id;
            },
            'company_id' => fn () => Company::factory(),
            'title' => $this->faker->company().' deal',
            'amount' => 0,
            'currency' => 'RUB',
            'owner_user_id' => fn () => User::factory(),
            'department_id' => null,
            'contract_id' => null,
            'lost_reason' => null,
            'lost_reason_id' => null,
            'tags' => [],
            'extra_fields' => [],
            'expected_close_date' => null,
            'expected_sign_date' => null,
            'expected_payment_date' => null,
            'kp_sent_at' => null,
            'contract_sent_at' => null,
            'stage_changed_at' => now(),
            'closed_at' => null,
        ];
    }

    /**
     * Place the deal in a specific stage (and its pipeline).
     */
    public function inStage(PipelineStage $stage): static
    {
        return $this->state([
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);
    }

    public function forOwner(User $owner): static
    {
        return $this->state([
            'owner_user_id' => $owner->id,
            'department_id' => $owner->department_id,
        ]);
    }
}
