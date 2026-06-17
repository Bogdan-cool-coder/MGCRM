<?php

declare(strict_types=1);

namespace Database\Factories\Inbox;

use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
