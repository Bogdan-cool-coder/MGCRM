<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicensorEntity>
 */
class LicensorEntityFactory extends Factory
{
    protected $model = LicensorEntity::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'country_code' => strtolower(Str::random(2)).$seq,
            'is_default' => true,
            'legal_form' => 'ТОО',
            'full_legal_form' => 'Товарищество с ограниченной ответственностью',
            'gender_ending_oe' => 'ое',
            'name' => $this->faker->company(),
            'director_position' => 'Директора',
            'director_short' => $this->faker->name(),
            'director_genitive' => $this->faker->name().' (genitive)',
            'acts_basis' => 'Устава',
            'tax_id_label' => 'БИН',
            'tax_id' => $this->faker->numerify('############'),
            'address' => $this->faker->address(),
            'bank' => $this->faker->company().' Bank',
            'bank_code_label' => 'БИК',
            'bank_code' => strtoupper(Str::random(8)),
            'account' => $this->faker->numerify('KZ ####################'),
            'phone' => null,
            'email' => $this->faker->safeEmail(),
            'website' => $this->faker->domainName(),
            'training_login' => null,
        ];
    }

    public function forKz(): static
    {
        return $this->state([
            'country_code' => 'kz',
            'legal_form' => 'ТОО',
        ]);
    }

    public function forUz(): static
    {
        return $this->state([
            'country_code' => 'uz',
            'legal_form' => 'ООО',
        ]);
    }
}
