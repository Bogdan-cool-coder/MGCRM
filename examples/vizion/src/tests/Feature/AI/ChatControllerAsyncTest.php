<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the async-flavoured POST /api/chats/{chat}/messages and the
 * updated GET /api/chats/{chat}/messages. M4 pivots the controller from
 * "synchronously wait for the AI" to "dispatch a job, return 202 with a
 * stream_url". These tests pin the new HTTP contract.
 *
 * sendMessage end-to-end with a real ChatService::runForJob() invocation is
 * already covered in ProcessChatMessageJobTest. Here we focus on the HTTP
 * surface: status codes, payload shape, queue dispatch, and the
 * one-active-turn-per-chat guard.
 */
class ChatControllerAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tame MacroData dependencies that get resolved transitively when the
        // controller materialises ChatService → ReportTool → DataProbeService.
        $this->app->instance(ConfigNormalizer::class, new class extends ConfigNormalizer {
            public function __construct() {}

            public function getCanonicalMap(): array
            {
                return ['models' => [], 'relations' => [], 'related' => []];
            }
        });

        $this->app->instance(ConnectionService::class, new class extends ConnectionService {
            public function __construct() {}
            public function connect(Company $company): void {}
            public function test(Company $company): bool { return true; }
        });
    }

    private function makeUserAndChat(): array
    {
        $company = Company::create([
            'name'               => 'AsyncCo',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);

        $user = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'analyst',
        ]);

        $chat = Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);

        return [$user, $chat];
    }

    public function test_post_returns_202_with_stream_url_and_assistant_message_in_pending(): void
    {
        Queue::fake();

        [$user, $chat] = $this->makeUserAndChat();

        $response = $this->actingAs($user)->postJson("/api/chats/{$chat->id}/messages", [
            'content' => 'Build me a report on deals',
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'user_message'      => ['id', 'role', 'content', 'created_at'],
            'assistant_message' => ['id', 'role', 'status', 'content', 'created_at'],
            'stream_url',
            'chat',
        ]);

        $body = $response->json();

        $this->assertSame('user', $body['user_message']['role']);
        $this->assertSame('Build me a report on deals', $body['user_message']['content']);

        $this->assertSame('assistant', $body['assistant_message']['role']);
        $this->assertSame(ChatMessage::STATUS_PENDING, $body['assistant_message']['status']);
        $this->assertNull($body['assistant_message']['content'], 'pending assistant message must have null content');

        $expectedStreamUrl = "/api/chats/{$chat->id}/stream/{$body['assistant_message']['id']}";
        $this->assertSame($expectedStreamUrl, $body['stream_url']);
    }

    public function test_post_dispatches_process_chat_message_job(): void
    {
        Queue::fake();

        [$user, $chat] = $this->makeUserAndChat();

        $response = $this->actingAs($user)->postJson("/api/chats/{$chat->id}/messages", [
            'content' => 'iterate report config',
        ]);

        $response->assertStatus(202);
        $assistantId = $response->json('assistant_message.id');

        Queue::assertPushed(
            ProcessChatMessageJob::class,
            fn (ProcessChatMessageJob $job) => $job->assistantMessageId === (int) $assistantId
        );
    }

    public function test_post_sets_title_from_first_message(): void
    {
        Queue::fake();

        [$user, $chat] = $this->makeUserAndChat();
        $this->assertNull($chat->title);

        $longContent = str_repeat('заголовок ', 30); // far over 80 chars

        $this->actingAs($user)->postJson("/api/chats/{$chat->id}/messages", [
            'content' => $longContent,
        ])->assertStatus(202);

        $chat->refresh();
        $this->assertNotNull($chat->title);
        $this->assertSame(mb_substr($longContent, 0, 80), $chat->title);
    }

    public function test_post_returns_409_when_a_turn_is_already_running(): void
    {
        Queue::fake();

        [$user, $chat] = $this->makeUserAndChat();

        // Simulate a previously dispatched turn that hasn't finished yet.
        ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => null,
            'status'     => ChatMessage::STATUS_RUNNING,
        ]);

        $response = $this->actingAs($user)->postJson("/api/chats/{$chat->id}/messages", [
            'content' => 'rapid double-send',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['code' => 'turn_in_progress']);

        // No new job should have been pushed.
        Queue::assertNothingPushed();
    }

    public function test_post_validates_required_content(): void
    {
        Queue::fake();

        [$user, $chat] = $this->makeUserAndChat();

        $this->actingAs($user)->postJson("/api/chats/{$chat->id}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        Queue::assertNothingPushed();
    }

    public function test_get_messages_returns_status_started_at_finished_at_events_count(): void
    {
        [$user, $chat] = $this->makeUserAndChat();

        // Hand-craft two assistant messages with different lifecycle stages so
        // we exercise the column projection. Also attach a couple of events
        // to one of them so events_count is non-zero.
        $running = ChatMessage::create([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $chat->company_id,
            'role'       => 'assistant',
            'content'    => null,
            'status'     => ChatMessage::STATUS_RUNNING,
            'started_at' => now()->subSeconds(10),
        ]);

        ChatMessageEvent::create([
            'chat_message_id' => $running->id,
            'sequence'        => 1,
            'type'            => ChatMessageEvent::TYPE_STARTED,
            'payload'         => [],
        ]);
        ChatMessageEvent::create([
            'chat_message_id' => $running->id,
            'sequence'        => 2,
            'type'            => ChatMessageEvent::TYPE_THINKING,
            'payload'         => [],
        ]);

        $done = ChatMessage::create([
            'chat_id'     => $chat->id,
            'user_id'     => $user->id,
            'company_id'  => $chat->company_id,
            'role'        => 'assistant',
            'content'     => 'Ready.',
            'status'      => ChatMessage::STATUS_DONE,
            'started_at'  => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
        ]);

        $response = $this->actingAs($user)->getJson("/api/chats/{$chat->id}/messages");
        $response->assertOk();

        $byId = collect($response->json())->keyBy('id');

        $this->assertTrue($byId->has($running->id));
        $this->assertSame(ChatMessage::STATUS_RUNNING, $byId[$running->id]['status']);
        $this->assertNotNull($byId[$running->id]['started_at']);
        $this->assertNull($byId[$running->id]['finished_at']);
        $this->assertSame(2, $byId[$running->id]['events_count']);

        $this->assertTrue($byId->has($done->id));
        $this->assertSame(ChatMessage::STATUS_DONE, $byId[$done->id]['status']);
        $this->assertNotNull($byId[$done->id]['finished_at']);
        $this->assertSame(0, $byId[$done->id]['events_count']);
    }

    public function test_post_refuses_access_for_chat_from_a_different_company(): void
    {
        Queue::fake();

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

        $this->actingAs($user)->postJson("/api/chats/{$foreignChat->id}/messages", [
            'content' => 'hello from another company',
        ])->assertStatus(403);

        Queue::assertNothingPushed();
    }
}
