<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealContact>
 */
class DealContactFactory extends Factory
{
    protected $model = DealContact::class;

    public function definition(): array
    {
        return [
            'deal_id' => fn () => Deal::factory(),
            'contact_id' => fn () => Contact::factory(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
