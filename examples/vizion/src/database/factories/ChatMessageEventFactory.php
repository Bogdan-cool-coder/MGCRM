<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessageEvent>
 *
 * Minimal factory for ChatMessageEvent used by the M2 model tests. Future M4
 * tests will lean on it more heavily (event-stream assertions). The default
 * shape is the simplest valid event: a 'started' marker with an empty payload.
 *
 * Note: `sequence` defaults to 1 — when creating multiple events per message
 * the caller is responsible for passing the correct sequence (or using the
 * forSequence() state below) to honour the (chat_message_id, sequence) unique
 * index.
 */
class ChatMessageEventFactory extends Factory
{
    protected $model = ChatMessageEvent::class;

    public function definition(): array
    {
        return [
            'chat_message_id' => ChatMessage::query()->value('id') ?? 0,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ];
    }

    public function forMessage(ChatMessage $message): static
    {
        return $this->state(fn () => ['chat_message_id' => $message->id]);
    }

    public function forSequence(int $sequence): static
    {
        return $this->state(fn () => ['sequence' => $sequence]);
    }

    public function ofType(string $type, array $payload = []): static
    {
        return $this->state(fn () => ['type' => $type, 'payload' => $payload]);
    }
}
