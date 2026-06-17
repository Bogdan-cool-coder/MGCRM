<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'kind' => 'contract',
            'number' => null,
            'title' => $this->faker->company(),
            'product_code' => $this->faker->randomElement(['macrocrm', 'macrosales', 'macroerp']),
            'country_code' => $this->faker->randomElement(['kz', 'uz']),
            'city' => $this->faker->city(),
            'city_code' => null,
            'status' => ContractStatus::Draft->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'currency' => 'KZT',
            'subtotal' => 0,
            'discount_pct' => 0.00,
            'discount_amount' => 0,
            'total' => 0,
            'extra_fields' => [],
            'author_user_id' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ContractStatus::Draft->value]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => ContractStatus::Submitted->value]);
    }

    public function approved(): static
    {
        return $this->state(['status' => ContractStatus::Approved->value]);
    }

    public function inReview(): static
    {
        return $this->state(['status' => ContractStatus::InReview->value]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => ContractStatus::Archived->value,
            'archived_at' => now(),
        ]);
    }

    public function withContext(array $context): static
    {
        return $this->state(['context' => $context]);
    }
}
