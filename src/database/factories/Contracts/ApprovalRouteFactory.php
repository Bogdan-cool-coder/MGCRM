<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRoute>
 */
class ApprovalRouteFactory extends Factory
{
    protected $model = ApprovalRoute::class;

    public function definition(): array
    {
        $approver = User::factory()->create(['role' => 'lawyer']);

        return [
            'title' => 'Default Approval Route',
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [
                [
                    'order' => 1,
                    'name' => 'Юрист',
                    'user_ids' => [$approver->id],
                    'min_required' => 1,
                ],
            ],
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    /**
     * Create a two-stage route with the given user IDs.
     *
     * @param  list<int>  $stage1UserIds
     * @param  list<int>  $stage2UserIds
     */
    public function twoStage(array $stage1UserIds, array $stage2UserIds, int $stage1MinRequired = 1, int $stage2MinRequired = 1): static
    {
        return $this->state([
            'stages' => [
                [
                    'order' => 1,
                    'name' => 'Этап 1',
                    'user_ids' => $stage1UserIds,
                    'min_required' => $stage1MinRequired,
                ],
                [
                    'order' => 2,
                    'name' => 'Этап 2',
                    'user_ids' => $stage2UserIds,
                    'min_required' => $stage2MinRequired,
                ],
            ],
        ]);
    }

    /**
     * Single-stage route with the given user IDs.
     *
     * @param  list<int>  $userIds
     */
    public function singleStage(array $userIds, int $minRequired = 1): static
    {
        return $this->state([
            'is_default' => true,
            'stages' => [
                [
                    'order' => 1,
                    'name' => 'Согласование',
                    'user_ids' => $userIds,
                    'min_required' => $minRequired,
                ],
            ],
        ]);
    }
}
