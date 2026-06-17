<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicensorBankAccount>
 */
class LicensorBankAccountFactory extends Factory
{
    protected $model = LicensorBankAccount::class;

    public function definition(): array
    {
        $currencies = ['KZT', 'UZS', 'RUB', 'USD', 'EUR'];

        return [
            'licensor_id' => LicensorEntity::factory(),
            'currency' => $this->faker->randomElement($currencies),
            'bank' => $this->faker->company().' Bank',
            'bank_code_label' => 'БИК',
            'bank_code' => strtoupper(Str::random(8)),
            'account' => $this->faker->numerify('KZ ####################'),
            'swift' => null,
            'is_primary' => false,
            'note' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
