<?php

declare(strict_types=1);

namespace Database\Factories\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => NotificationCategory::System->value,
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->optional()->sentence(),
            'is_actionable' => false,
            'deep_link' => null,
            'data' => null,
            'read_at' => null,
        ];
    }

    public function actionable(): self
    {
        return $this->state(fn (): array => [
            'is_actionable' => true,
            'action_label' => 'Открыть',
            'category' => NotificationCategory::Task->value,
        ]);
    }

    public function read(): self
    {
        return $this->state(fn (): array => [
            'read_at' => now(),
        ]);
    }

    public function category(NotificationCategory $category): self
    {
        return $this->state(fn (): array => [
            'category' => $category->value,
        ]);
    }
}
