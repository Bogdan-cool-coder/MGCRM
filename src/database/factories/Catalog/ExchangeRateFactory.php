<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        $supported = ['RUB', 'USD', 'EUR', 'KZT'];
        $from = $this->faker->randomElement($supported);
        do {
            $to = $this->faker->randomElement($supported);
        } while ($to === $from);

        return [
            'from_code' => $from,
            'to_code' => $to,
            'rate' => number_format($this->faker->randomFloat(6, 0.01, 100), 6, '.', ''),
            'date' => Carbon::today()->toDateString(),
            'source' => 'manual',
            'created_at' => now(),
        ];
    }
}
