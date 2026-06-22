<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyRequisite>
 */
class CompanyRequisiteFactory extends Factory
{
    protected $model = CompanyRequisite::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'legal_name' => $this->faker->company().' ТОО',
            'full_legal_form' => 'Товарищество с ограниченной ответственностью',
            'legal_form' => 'ТОО',
            'director_genitive' => 'Директора '.$this->faker->name(),
            'director_short' => $this->faker->name(),
            'director_position' => 'Директор',
            'acts_basis' => 'Устава',
            'tax_id_label' => 'БИН',
            'tax_id' => (string) $this->faker->numerify('############'),
            'country_code' => 'kz',
            'address' => $this->faker->address(),
            'bank_details' => [
                'bank' => 'Народный Банк Казахстана',
                'bank_code_label' => 'БИК',
                'bank_code' => 'HSBKKZKX',
                'account' => 'KZ'.$this->faker->numerify('####################'),
            ],
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(['is_current' => true]);
    }
}
