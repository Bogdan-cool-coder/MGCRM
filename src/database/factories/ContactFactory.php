<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'position' => fake()->jobTitle(),
            'source' => 'own_contact',
            'status' => 'active',
            'tags' => [],
            'extra_fields' => [],
        ];
    }
}
