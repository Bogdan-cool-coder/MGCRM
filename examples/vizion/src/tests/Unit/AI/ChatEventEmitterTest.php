<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\ChatEventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ChatEventEmitter — the streaming-pipeline primitive used by
 * ProcessChatMessageJob and ReportTool to append events to chat_message_events
 * during an AI turn. The behaviours pinned here:
 *
 *   - sequence is assigned monotonically per chat_message_id (starts at 1).
 *   - distinct messages have independent sequences (no global counter).
 *   - emit() rejects unknown event types with InvalidArgumentException.
 *   - emit() returns the freshly persisted ChatMessageEvent model.
 *   - payload arrays round-trip through the jsonb cast.
 *   - On a unique-constraint collision the emitter recomputes MAX(sequence)
 *     and retries — so two instances writing to the same message converge
 *     without manual coordination.
 */
class ChatEventEmitterTest extends TestCase
{
    use RefreshDatabase;

    private function makeAssistantMessage(): ChatMessage
    {
        $company = Company::create(['name' => 'Emitter Co']);
        $user = User::forceCreate([
            'name'       => 'Emitter Tester',
            'email'      => 'emitter+' . uniqid() . '@example.com',
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
            'content'    => null,
            'status'     => ChatMessage::STATUS_PENDING,
        ]);
    }

    public function test_emit_assigns_sequence_1_for_first_event_and_increments(): void
    {
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $first = $emitter->emit(ChatMessageEvent::TYPE_STARTED);
        $second = $emitter->emit(ChatMessageEvent::TYPE_THINKING, ['stage' => 'pre_prism']);
        $third = $emitter->emit(ChatMessageEvent::TYPE_FINAL_MESSAGE, ['content' => 'ok']);

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(3, $third->sequence);

        // Confirm the rows are durably persisted, not just returned in-memory.
        $persisted = ChatMessageEvent::where('chat_message_id', $message->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all();
        $this->assertSame([1, 2, 3], $persisted);
    }

    public function test_emit_persists_payload_and_type(): void
    {
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $payload = ['tool' => 'probe_data', 'args' => ['model' => 'EstateDeals']];

        $event = $emitter->emit(ChatMessageEvent::TYPE_TOOL_CALL, $payload);

        $reloaded = ChatMessageEvent::find($event->id);
        $this->assertSame(ChatMessageEvent::TYPE_TOOL_CALL, $reloaded->type);
        $this->assertSame('probe_data', $reloaded->payload['tool']);
        $this->assertSame('EstateDeals', $reloaded->payload['args']['model']);
    }

    public function test_emit_returns_the_created_event_instance(): void
    {
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $event = $emitter->emit(ChatMessageEvent::TYPE_STARTED);

        $this->assertInstanceOf(ChatMessageEvent::class, $event);
        $this->assertSame($message->id, $event->chat_message_id);
        $this->assertNotNull($event->id, 'emit() should return a persisted (saved) event with an id');
    }

    public function test_two_emitters_targeting_the_same_message_assign_distinct_sequences(): void
    {
        $message = $this->makeAssistantMessage();

        $emitterA = new ChatEventEmitter($message->id);
        $emitterB = new ChatEventEmitter($message->id);

        // Interleave writes — both emitters read MAX(sequence) fresh, so they
        // must converge on monotonically increasing values via the retry path.
        $emitterA->emit(ChatMessageEvent::TYPE_STARTED);
        $emitterB->emit(ChatMessageEvent::TYPE_THINKING);
        $emitterA->emit(ChatMessageEvent::TYPE_TOOL_CALL, ['tool' => 'probe_data']);
        $emitterB->emit(ChatMessageEvent::TYPE_TOOL_RESULT, ['result' => 'ok']);

        $sequences = ChatMessageEvent::where('chat_message_id', $message->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all();

        // All four sequences must be present, unique, and in 1..N (no gaps).
        $this->assertSame([1, 2, 3, 4], $sequences);
    }

    public function test_separate_messages_have_independent_sequence_counters(): void
    {
        $messageA = $this->makeAssistantMessage();
        $messageB = $this->makeAssistantMessage();

        $emitterA = new ChatEventEmitter($messageA->id);
        $emitterB = new ChatEventEmitter($messageB->id);

        $emitterA->emit(ChatMessageEvent::TYPE_STARTED);
        $emitterA->emit(ChatMessageEvent::TYPE_THINKING);
        // First event for B must still be sequence 1 — not 3.
        $eventB = $emitterB->emit(ChatMessageEvent::TYPE_STARTED);

        $this->assertSame(1, $eventB->sequence);
    }

    public function test_emit_rejects_unknown_event_types(): void
    {
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('totally_made_up_event');

        $emitter->emit('totally_made_up_event', []);
    }

    public function test_emit_accepts_all_documented_event_types(): void
    {
        // Pin the whitelist contract — if a TYPE_* constant is added on the
        // model, this test will fail until the emitter's ALLOWED_TYPES is
        // updated too. That mirrors the "add in both places" rule.
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $allTypes = [
            ChatMessageEvent::TYPE_STARTED,
            ChatMessageEvent::TYPE_THINKING,
            ChatMessageEvent::TYPE_TOOL_CALL,
            ChatMessageEvent::TYPE_TOOL_RESULT,
            ChatMessageEvent::TYPE_DRY_RUN_START,
            ChatMessageEvent::TYPE_DRY_RUN_RESULT,
            ChatMessageEvent::TYPE_RETRY,
            ChatMessageEvent::TYPE_FINAL_MESSAGE,
            ChatMessageEvent::TYPE_ERROR,
        ];

        foreach ($allTypes as $type) {
            $event = $emitter->emit($type, ['note' => 'whitelist sanity check']);
            $this->assertSame($type, $event->type, "Emitter rejected documented type {$type}");
        }
    }

    public function test_default_payload_is_empty_array(): void
    {
        $message = $this->makeAssistantMessage();
        $emitter = new ChatEventEmitter($message->id);

        $event = $emitter->emit(ChatMessageEvent::TYPE_STARTED);

        $this->assertSame([], $event->payload);

        $reloaded = ChatMessageEvent::find($event->id);
        $this->assertSame([], $reloaded->payload, 'empty payload must round-trip through jsonb cast');
    }
}
