<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the two streaming-event HTTP endpoints (M5 + M6):
 *
 *  - GET /api/chats/{chat}/stream/{message}            — SSE live stream
 *  - GET /api/chats/{chat}/messages/{message}/events   — JSON batch reader
 *
 * The SSE controller deliberately self-terminates when the parent message is
 * in a terminal status (done / error / cancelled) and there are no more
 * events to drain. All tests below seed the message in STATUS_DONE before
 * calling streamedContent(), which means the inner loop fires once, drains
 * the buffered events, emits a `done` sentinel and returns — keeping the
 * test fast and deterministic. We never exercise the wall-clock-budget
 * branch from a test (480 seconds would hang the suite); behaviour there
 * is covered by the smaller unit-shaped checks (cursor resolution, etc.).
 */
class ChatStreamControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndChat(array $userOverrides = []): array
    {
        $company = Company::create([
            'name'               => 'StreamCo',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://stream.test',
        ]);

        $user = User::factory()->create(array_merge([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ], $userOverrides));

        $chat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);

        return [$user, $chat, $company];
    }

    /**
     * Seed an assistant message and N events on it. Status defaults to DONE
     * so the SSE loop self-terminates immediately after draining.
     */
    private function seedAssistantMessageWithEvents(
        Chat $chat,
        User $user,
        int $eventCount,
        string $status = ChatMessage::STATUS_DONE,
    ): ChatMessage {
        $message = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => $status === ChatMessage::STATUS_DONE ? 'Result.' : null,
            'status'     => $status,
        ]);

        for ($i = 1; $i <= $eventCount; $i++) {
            ChatMessageEvent::create([
                'chat_message_id' => $message->id,
                'sequence'        => $i,
                'type'            => $i === 1
                    ? ChatMessageEvent::TYPE_STARTED
                    : ChatMessageEvent::TYPE_THINKING,
                'payload'         => ['step' => $i],
            ]);
        }

        return $message;
    }

    // ------------------------------------------------------------------
    // SSE endpoint: GET /api/chats/{chat}/stream/{message}
    // ------------------------------------------------------------------

    public function test_stream_returns_404_when_message_does_not_belong_to_chat(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        [, $otherChat] = $this->makeUserAndChat();

        $foreignMessage = $this->seedAssistantMessageWithEvents($otherChat, $user, 1);

        $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$foreignMessage->id}")
            ->assertStatus(404);
    }

    public function test_stream_returns_403_for_chat_from_a_different_company(): void
    {
        [$user, $chat] = $this->makeUserAndChat();

        $otherCompany = Company::create([
            'name'               => 'OtherCo',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://other.test',
        ]);
        $foreignChat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $otherCompany->id,
            'type'       => 'report_generation',
        ]);
        $foreignMessage = $this->seedAssistantMessageWithEvents($foreignChat, $user, 1);

        $this->actingAs($user)
            ->get("/api/chats/{$foreignChat->id}/stream/{$foreignMessage->id}")
            ->assertStatus(403);
    }

    public function test_stream_returns_422_when_message_is_user_role(): void
    {
        [$user, $chat] = $this->makeUserAndChat();

        $userMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'user',
            'content'    => 'hi',
            'status'     => ChatMessage::STATUS_DONE,
        ]);

        $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$userMessage->id}")
            ->assertStatus(422)
            ->assertJson(['code' => 'not_streamable']);
    }

    public function test_stream_emits_all_events_then_done_sentinel_for_finished_message(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        $response = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        // Laravel normalises Cache-Control alphabetically + adds `private` for
        // authenticated requests. Just sanity-check that the no-cache pieces
        // are present rather than locking the exact ordering.
        $response->assertHeaderMissing('Etag');
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $response->assertHeader('X-Accel-Buffering', 'no');

        $body = $response->streamedContent();

        // Three events with id: 1 / 2 / 3 must show up in order...
        $this->assertStringContainsString("id: 1\n", $body);
        $this->assertStringContainsString("id: 2\n", $body);
        $this->assertStringContainsString("id: 3\n", $body);

        // ...with the right event types attached.
        $this->assertStringContainsString("event: " . ChatMessageEvent::TYPE_STARTED . "\n", $body);
        $this->assertStringContainsString("event: " . ChatMessageEvent::TYPE_THINKING . "\n", $body);

        // Payload is JSON-encoded; the `step` key from seed should be present.
        $this->assertStringContainsString('"sequence":1', $body);
        $this->assertStringContainsString('"step":1', $body);
        $this->assertStringContainsString('"step":3', $body);

        // Terminal sentinel.
        $this->assertStringContainsString("event: done\n", $body);
        $this->assertStringContainsString('"status":"' . ChatMessage::STATUS_DONE . '"', $body);

        // Ordering check: sentinel must be AFTER the last id: 3 frame.
        $idxLast    = strrpos($body, "id: 3");
        $idxDone    = strrpos($body, "event: done");
        $this->assertNotFalse($idxLast);
        $this->assertNotFalse($idxDone);
        $this->assertGreaterThan($idxLast, $idxDone);
    }

    public function test_stream_with_since_query_param_skips_already_seen_events(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        $body = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}?since=1")
            ->streamedContent();

        // sequence 1 must NOT appear in payload form...
        $this->assertStringNotContainsString("id: 1\n", $body);
        $this->assertStringNotContainsString('"sequence":1', $body);

        // ...but sequences 2 and 3 must.
        $this->assertStringContainsString("id: 2\n", $body);
        $this->assertStringContainsString("id: 3\n", $body);
        $this->assertStringContainsString("event: done\n", $body);
    }

    public function test_stream_falls_back_to_last_event_id_header_when_since_missing(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        $body = $this->actingAs($user)
            ->withHeaders(['Last-Event-ID' => '2'])
            ->get("/api/chats/{$chat->id}/stream/{$message->id}")
            ->streamedContent();

        // Only sequence 3 should be present from the events list.
        $this->assertStringNotContainsString("id: 1\n", $body);
        $this->assertStringNotContainsString("id: 2\n", $body);
        $this->assertStringContainsString("id: 3\n", $body);
        $this->assertStringContainsString("event: done\n", $body);
    }

    public function test_stream_explicit_since_overrides_last_event_id_header(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        // since=0 (explicit "replay from the start") must beat the header
        // which says "I already saw 2".
        $body = $this->actingAs($user)
            ->withHeaders(['Last-Event-ID' => '2'])
            ->get("/api/chats/{$chat->id}/stream/{$message->id}?since=0")
            ->streamedContent();

        $this->assertStringContainsString("id: 1\n", $body);
        $this->assertStringContainsString("id: 2\n", $body);
        $this->assertStringContainsString("id: 3\n", $body);
    }

    public function test_stream_emits_only_sentinel_when_message_terminal_with_no_events(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 0);

        $body = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}")
            ->streamedContent();

        $this->assertStringNotContainsString("id: ", $body);
        $this->assertStringContainsString("event: done\n", $body);
        $this->assertStringContainsString('"status":"' . ChatMessage::STATUS_DONE . '"', $body);
    }

    public function test_stream_handles_error_status_as_terminal(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents(
            $chat,
            $user,
            1,
            ChatMessage::STATUS_ERROR,
        );

        $body = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}")
            ->streamedContent();

        $this->assertStringContainsString("id: 1\n", $body);
        $this->assertStringContainsString("event: done\n", $body);
        $this->assertStringContainsString('"status":"' . ChatMessage::STATUS_ERROR . '"', $body);
    }

    public function test_stream_handles_cancelled_status_as_terminal(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents(
            $chat,
            $user,
            0,
            ChatMessage::STATUS_CANCELLED,
        );

        $body = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}")
            ->streamedContent();

        $this->assertStringContainsString("event: done\n", $body);
        $this->assertStringContainsString('"status":"' . ChatMessage::STATUS_CANCELLED . '"', $body);
    }

    public function test_stream_payload_preserves_unicode_unescaped(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => 'Готово.',
            'status'     => ChatMessage::STATUS_DONE,
        ]);
        ChatMessageEvent::create([
            'chat_message_id' => $message->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_THINKING,
            'payload'         => ['note' => 'построение отчёта'],
        ]);

        $body = $this->actingAs($user)
            ->get("/api/chats/{$chat->id}/stream/{$message->id}")
            ->streamedContent();

        // We use JSON_UNESCAPED_UNICODE in the SSE writer so the Cyrillic
        // round-trips as-is rather than \uXXXX escapes that would force the
        // frontend to decode twice.
        $this->assertStringContainsString('построение отчёта', $body);
    }

    // ------------------------------------------------------------------
    // Batch endpoint: GET /api/chats/{chat}/messages/{message}/events
    // ------------------------------------------------------------------

    public function test_events_returns_404_when_message_does_not_belong_to_chat(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        [, $otherChat] = $this->makeUserAndChat();

        $foreignMessage = $this->seedAssistantMessageWithEvents($otherChat, $user, 1);

        $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$foreignMessage->id}/events")
            ->assertStatus(404);
    }

    public function test_events_returns_403_for_chat_from_a_different_company(): void
    {
        [$user, $chat] = $this->makeUserAndChat();

        $otherCompany = Company::create([
            'name'               => 'OtherCo',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://other.test',
        ]);
        $foreignChat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $otherCompany->id,
            'type'       => 'report_generation',
        ]);
        $foreignMessage = $this->seedAssistantMessageWithEvents($foreignChat, $user, 1);

        $this->actingAs($user)
            ->getJson("/api/chats/{$foreignChat->id}/messages/{$foreignMessage->id}/events")
            ->assertStatus(403);
    }

    public function test_events_returns_422_for_user_role_message(): void
    {
        [$user, $chat] = $this->makeUserAndChat();

        $userMessage = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'user',
            'content'    => 'hi',
            'status'     => ChatMessage::STATUS_DONE,
        ]);

        $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$userMessage->id}/events")
            ->assertStatus(422);
    }

    public function test_events_returns_full_event_array_and_message_status(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        $response = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events");

        $response->assertOk();
        $response->assertJsonStructure([
            'events' => [
                '*' => ['sequence', 'type', 'payload', 'created_at'],
            ],
            'message_status',
            'has_more',
            'next_cursor',
        ]);

        $body = $response->json();

        $this->assertCount(3, $body['events']);
        $this->assertSame(1, $body['events'][0]['sequence']);
        $this->assertSame(2, $body['events'][1]['sequence']);
        $this->assertSame(3, $body['events'][2]['sequence']);

        $this->assertSame(ChatMessage::STATUS_DONE, $body['message_status']);
        $this->assertFalse($body['has_more']);
        $this->assertNull($body['next_cursor']);
    }

    public function test_events_honours_since_cursor(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 5);

        $body = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events?since=2")
            ->assertOk()
            ->json();

        $this->assertCount(3, $body['events']);
        $this->assertSame([3, 4, 5], array_column($body['events'], 'sequence'));
    }

    public function test_events_pagination_with_limit_and_next_cursor(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 5);

        // First page: limit=2 → events 1,2; has_more=true; next_cursor=2
        $page1 = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events?limit=2")
            ->assertOk()
            ->json();

        $this->assertCount(2, $page1['events']);
        $this->assertSame([1, 2], array_column($page1['events'], 'sequence'));
        $this->assertTrue($page1['has_more']);
        $this->assertSame(2, $page1['next_cursor']);

        // Second page: continue from cursor → events 3,4
        $page2 = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events?limit=2&since={$page1['next_cursor']}")
            ->assertOk()
            ->json();

        $this->assertCount(2, $page2['events']);
        $this->assertSame([3, 4], array_column($page2['events'], 'sequence'));
        $this->assertTrue($page2['has_more']);
        $this->assertSame(4, $page2['next_cursor']);

        // Third page: only event 5; has_more=false; next_cursor=null
        $page3 = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events?limit=2&since={$page2['next_cursor']}")
            ->assertOk()
            ->json();

        $this->assertCount(1, $page3['events']);
        $this->assertSame([5], array_column($page3['events'], 'sequence'));
        $this->assertFalse($page3['has_more']);
        $this->assertNull($page3['next_cursor']);
    }

    public function test_events_clamps_limit_to_max(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 3);

        // Ask for 99999 — controller must clamp; the response should still
        // succeed and return all 3 events without 500'ing.
        $body = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events?limit=99999")
            ->assertOk()
            ->json();

        $this->assertCount(3, $body['events']);
        $this->assertFalse($body['has_more']);
    }

    public function test_events_returns_empty_list_for_message_with_no_events(): void
    {
        [$user, $chat] = $this->makeUserAndChat();
        $message = $this->seedAssistantMessageWithEvents($chat, $user, 0);

        $body = $this->actingAs($user)
            ->getJson("/api/chats/{$chat->id}/messages/{$message->id}/events")
            ->assertOk()
            ->json();

        $this->assertSame([], $body['events']);
        $this->assertFalse($body['has_more']);
        $this->assertNull($body['next_cursor']);
        $this->assertSame(ChatMessage::STATUS_DONE, $body['message_status']);
    }
}
