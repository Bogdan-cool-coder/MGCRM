<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'subject' => null,
            'body' => 'Здравствуйте, {{contact.full_name}}! '.$this->faker->sentence(),
            'description' => null,
            'is_active' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    /**
     * Template with an email subject line.
     */
    public function withSubject(string $subject = 'Тема письма'): static
    {
        return $this->state(['subject' => $subject]);
    }

    /**
     * Soft-deleted (inactive) template.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
