<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ChatMessageEvent — verifies the event-log primitives that the M4
 * streaming pipeline relies on: array casting on the payload jsonb column,
 * created_at presence + updated_at absence (events are immutable), and the
 * cascade-on-delete behaviour that keeps the audit log free of orphans.
 */
class ChatMessageEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a minimal chat + assistant message fixture. Returns the message
     * so individual tests can attach events to it.
     */
    private function makeMessage(): ChatMessage
    {
        $company = Company::create(['name' => 'Event Test Co']);
        $user = User::forceCreate([
            'name'       => 'Tester',
            'email'      => 'event+'.uniqid().'@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
        ]);
        $chat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);

        return ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'role'       => 'assistant',
            'content'    => '',
            'status'     => ChatMessage::STATUS_PENDING,
        ]);
    }

    public function test_payload_is_cast_to_array(): void
    {
        $message = $this->makeMessage();

        $event = ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_TOOL_CALL,
            'payload'         => ['tool' => 'probe_data', 'args' => ['model' => 'EstateDeals']],
        ]);

        $fresh = ChatMessageEvent::find($event->id);

        $this->assertIsArray($fresh->payload);
        $this->assertSame('probe_data', $fresh->payload['tool']);
        $this->assertSame('EstateDeals', $fresh->payload['args']['model']);
    }

    public function test_created_at_is_set_and_updated_at_is_not(): void
    {
        $message = $this->makeMessage();

        $event = ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ]);

        $this->assertNotNull($event->created_at, 'created_at must be auto-populated');
        $this->assertNull($event->updated_at, 'updated_at must NOT be touched — events are immutable');

        // Re-fetch fresh from DB to confirm the column is genuinely missing/null,
        // not just unset on the in-memory model.
        $row = ChatMessageEvent::query()
            ->where('id', $event->id)
            ->first()
            ->getAttributes();

        $this->assertArrayNotHasKey('updated_at', $row, 'updated_at column should not exist on chat_message_events');
    }

    public function test_cascade_delete_removes_events_when_parent_message_is_deleted(): void
    {
        $message = $this->makeMessage();

        foreach (range(1, 3) as $i) {
            ChatMessageEvent::create([
                'chat_message_id' => $message->id,
                'sequence'        => $i,
                'type'            => ChatMessageEvent::TYPE_THINKING,
                'payload'         => ['step' => $i],
            ]);
        }

        $this->assertSame(3, ChatMessageEvent::where('chat_message_id', $message->id)->count());

        $message->delete();

        $this->assertSame(
            0,
            ChatMessageEvent::where('chat_message_id', $message->id)->count(),
            'events must be cascade-deleted when their parent chat_message is removed'
        );
    }

    public function test_chat_message_events_relation_returns_events_ordered_by_sequence(): void
    {
        $message = $this->makeMessage();

        // Insert out of order to ensure the relation actually applies ORDER BY.
        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 3,
            'type'            => ChatMessageEvent::TYPE_FINAL_MESSAGE,
            'payload'         => [],
        ]);
        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ]);
        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 2,
            'type'            => ChatMessageEvent::TYPE_THINKING,
            'payload'         => [],
        ]);

        $sequences = $message->events()->pluck('sequence')->all();

        $this->assertSame([1, 2, 3], $sequences);
    }

    public function test_unique_constraint_blocks_duplicate_sequence_per_message(): void
    {
        $message = $this->makeMessage();

        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_THINKING,
            'payload'         => [],
        ]);
    }
}
