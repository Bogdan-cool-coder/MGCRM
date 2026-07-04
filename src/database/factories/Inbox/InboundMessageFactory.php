<?php

declare(strict_types=1);

namespace Database\Factories\Inbox;

use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<InboundMessage>
 */
class InboundMessageFactory extends Factory
{
    protected $model = InboundMessage::class;

    public function definition(): array
    {
        return [
            'channel_id' => fn () => Channel::factory(),
            'external_id' => null,
            'from_identifier' => $this->faker->safeEmail(),
            'from_name' => $this->faker->name(),
            'subject' => null,
            'body' => null,
            'raw_payload' => [],
            'target_deal_id' => null,
            'target_deal_created' => false,
            'routing_status' => null,
            'received_at' => now(),
        ];
    }

    /** Starred (starred_at = now). */
    public function starred(): static
    {
        return $this->state(fn (array $attributes): array => ['starred_at' => now()]);
    }

    /** Marked important. */
    public function important(): static
    {
        return $this->state(fn (array $attributes): array => ['important' => true]);
    }

    /** Actively snoozed until the given instant (defaults to a future timestamp). */
    public function snoozed(?Carbon $until = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'snoozed_until' => $until ?? now()->addDay(),
        ]);
    }

    /** Snoozed with an already-past `snoozed_until` — must behave as "returned". */
    public function snoozedPast(): static
    {
        return $this->state(fn (array $attributes): array => [
            'snoozed_until' => now()->subHour(),
        ]);
    }
}
