<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\ContractNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractNumberSequence>
 */
class ContractNumberSequenceFactory extends Factory
{
    protected $model = ContractNumberSequence::class;

    public function definition(): array
    {
        return [
            'city_code' => mb_strtoupper($this->faker->lexify('???')),
            'country_code' => $this->faker->randomElement(['KZ', 'UZ']),
            'start_number' => 220,
            'current_number' => 220,
        ];
    }
}
