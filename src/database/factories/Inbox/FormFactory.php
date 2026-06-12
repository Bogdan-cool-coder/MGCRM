<?php

declare(strict_types=1);

namespace Database\Factories\Inbox;

use App\Domain\Inbox\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' form',
            'public_slug' => Str::random(11),
            'fields' => [
                ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => false],
            ],
            'channel_id' => null,
            'thank_you_text' => 'Спасибо! Мы свяжемся с вами.',
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
