<?php

declare(strict_types=1);

namespace Database\Factories\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Models\TelegramLinkToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramLinkToken>
 */
class TelegramLinkTokenFactory extends Factory
{
    protected $model = TelegramLinkToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ];
    }

    /** State: already redeemed. */
    public function used(): static
    {
        return $this->state(fn (array $attributes): array => ['used_at' => now()]);
    }

    /** State: expired (TTL elapsed). */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subMinute()]);
    }
}
