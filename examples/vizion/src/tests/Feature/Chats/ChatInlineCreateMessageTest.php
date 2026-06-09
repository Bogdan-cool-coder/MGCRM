<?php

declare(strict_types=1);

namespace Tests\Feature\Chats;

use App\Jobs\ProcessChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the mini-chat inline create+send endpoint:
 * POST /api/chats/messages.
 *
 * The endpoint collapses "create chat + send first message" into one DB
 * transaction so we never get orphaned empty chats. Returns 202 with the
 * same envelope as POST /api/chats/{chat}/messages plus a `chat` object.
 */
class ChatInlineCreateMessageTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $home, string $role = 'admin'): User
    {
        return User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $home->id, 'role' => $role]],
        ]);
    }

    private function makeReport(Company $company, User $author, array $overrides = []): Report
    {
        return Report::create(array_merge([
            'title'        => ['ru' => 'Test', 'en' => 'Test'],
            'description'  => ['ru' => '', 'en' => ''],
            'config'       => ['primary_model' => 'Deal', 'columns' => []],
            'is_system'    => false,
            'is_published' => false,
            'user_id'      => $author->id,
            'company_id'   => $company->id,
        ], $overrides));
    }

    /** @test */
    public function creates_general_chat_and_dispatches_job(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Сколько активных сделок?',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_GENERAL)
            ->assertJsonPath('chat.type', 'quick_qa')
            ->assertJsonPath('chat.report_id', null)
            ->assertJsonPath('user_message.role', 'user')
            ->assertJsonPath('user_message.content', 'Сколько активных сделок?')
            ->assertJsonPath('assistant_message.role', 'assistant')
            ->assertJsonPath('assistant_message.status', ChatMessage::STATUS_PENDING)
            ->assertJsonStructure(['stream_url']);

        $chatId = $response->json('chat.id');
        $assistantId = $response->json('assistant_message.id');

        // Chat persisted with correct fields.
        $chat = Chat::findOrFail($chatId);
        $this->assertSame(Chat::SCOPE_GENERAL, $chat->scope_type);
        $this->assertSame('quick_qa', $chat->type);
        $this->assertNull($chat->report_id);
        $this->assertSame($user->id, $chat->user_id);
        $this->assertSame($company->id, $chat->company_id);
        $this->assertNotNull($chat->title); // seeded from first message
        $this->assertSame('Сколько активных сделок?', $chat->title);

        // Messages persisted.
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chatId,
            'role'    => 'user',
            'content' => 'Сколько активных сделок?',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'id'      => $assistantId,
            'chat_id' => $chatId,
            'role'    => 'assistant',
            'status'  => ChatMessage::STATUS_PENDING,
        ]);

        // stream_url matches the contract.
        $this->assertSame(
            "/api/chats/{$chatId}/stream/{$assistantId}",
            $response->json('stream_url'),
        );

        // Job dispatched exactly once with the new assistant message id.
        Bus::assertDispatched(ProcessChatMessageJob::class, function (ProcessChatMessageJob $job) use ($assistantId) {
            return $job->assistantMessageId === (int) $assistantId
                && $job->reportContext === null;
        });
        Bus::assertDispatchedTimes(ProcessChatMessageJob::class, 1);
    }

    /** @test */
    public function creates_report_generation_chat_when_type_supplied(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'report_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Построй отчёт по продажам за квартал.',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.type', 'report_generation')
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_GENERAL)
            ->assertJsonPath('chat.report_id', null)
            ->assertJsonPath('assistant_message.status', ChatMessage::STATUS_PENDING)
            ->assertJsonStructure(['stream_url']);

        $chatId = $response->json('chat.id');
        $assistantId = $response->json('assistant_message.id');

        // Chat persisted as report_generation — downstream (getTools /
        // buildSystemPrompt) routes off this field.
        $chat = Chat::findOrFail($chatId);
        $this->assertSame('report_generation', $chat->type);
        $this->assertSame(Chat::SCOPE_GENERAL, $chat->scope_type);
        $this->assertNull($chat->report_id);

        // user + pending assistant rows created.
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chatId,
            'role'    => 'user',
            'content' => 'Построй отчёт по продажам за квартал.',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'id'      => $assistantId,
            'chat_id' => $chatId,
            'role'    => 'assistant',
            'status'  => ChatMessage::STATUS_PENDING,
        ]);

        // Job dispatched once — report_generation rides the same async path.
        Bus::assertDispatched(ProcessChatMessageJob::class, function (ProcessChatMessageJob $job) use ($assistantId) {
            return $job->assistantMessageId === (int) $assistantId
                && $job->reportContext === null;
        });
        Bus::assertDispatchedTimes(ProcessChatMessageJob::class, 1);
    }

    /** @test */
    public function defaults_to_quick_qa_when_type_omitted(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        // No `type` field — backwards-compatible with the mini-chat caller.
        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.type', 'quick_qa');

        $chat = Chat::findOrFail($response->json('chat.id'));
        $this->assertSame('quick_qa', $chat->type);
    }

    /** @test */
    public function returns_422_for_invalid_type(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'type'       => 'something_else',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Chat::count());
        Bus::assertNotDispatched(ProcessChatMessageJob::class);
    }

    /** @test */
    public function viewer_cannot_create_report_generation_chat(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->postJson('/api/chats/messages', [
            'type'       => 'report_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Построй отчёт.',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Chat::count());
        Bus::assertNotDispatched(ProcessChatMessageJob::class);
    }

    /** @test */
    public function analyst_can_create_report_generation_chat(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/chats/messages', [
            'type'       => 'report_generation',
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Построй отчёт.',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.type', 'report_generation');

        Bus::assertDispatchedTimes(ProcessChatMessageJob::class, 1);
    }

    /** @test */
    public function creates_report_scope_chat_with_report_id(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_REPORT,
            'report_id'  => $report->id,
            'content'    => 'Уточни цифры по отчёту.',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('chat.scope_type', Chat::SCOPE_REPORT)
            ->assertJsonPath('chat.report_id', $report->id);

        $chatId = $response->json('chat.id');
        $this->assertSame($report->id, Chat::findOrFail($chatId)->report_id);
    }

    /** @test */
    public function passes_report_context_into_job(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);
        $report  = $this->makeReport($company, $user);

        $reportContext = [
            'primaryModel' => 'EstateDeals',
            'reportId'     => $report->id,
            'reportTitle'  => 'My report',
            'columns'      => ['deal_date', 'deal_sum'],
            'filters'      => ['deal_status' => 150],
        ];

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type'     => Chat::SCOPE_REPORT,
            'report_id'      => $report->id,
            'content'        => 'Сравни с прошлым месяцем.',
            'report_context' => $reportContext,
        ]);

        $response->assertStatus(202);

        Bus::assertDispatched(ProcessChatMessageJob::class, function (ProcessChatMessageJob $job) use ($reportContext) {
            return $job->reportContext === $reportContext;
        });
    }

    /** @test */
    public function returns_403_when_report_belongs_to_other_company(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');

        $user      = $this->makeUser($companyA, 'admin');
        $otherUser = $this->makeUser($companyB, 'admin');
        $foreignReport = $this->makeReport($companyB, $otherUser);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_REPORT,
            'report_id'  => $foreignReport->id,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(403);

        // No chat created.
        $this->assertSame(0, Chat::count());
        Bus::assertNotDispatched(ProcessChatMessageJob::class);
    }

    /** @test */
    public function returns_422_without_content(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function returns_422_without_scope_type(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'content' => 'Hi',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function returns_422_for_report_scope_without_report_id(): void
    {
        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_REPORT,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function returns_403_for_viewer_role(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Chat::count());
    }

    /** @test */
    public function rolls_back_when_dispatch_throws(): void
    {
        // Force a failure inside the transaction: stub ChatService::dispatchMessage
        // to throw. The transaction must roll back the freshly-created Chat.
        $this->app->bind(
            \App\Services\AI\ChatService::class,
            function () {
                $mock = $this->getMockBuilder(\App\Services\AI\ChatService::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['dispatchMessage'])
                    ->getMock();

                $mock->method('dispatchMessage')
                    ->willThrowException(new \RuntimeException('boom'));

                return $mock;
            }
        );

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        try {
            $this->actingAs($user)->postJson('/api/chats/messages', [
                'scope_type' => Chat::SCOPE_GENERAL,
                'content'    => 'Hi',
            ]);
        } catch (\Throwable $e) {
            // Expected — bubbles out of the transaction.
        }

        // No chat persisted — transaction rolled back.
        $this->assertSame(0, Chat::count());
        $this->assertSame(0, ChatMessage::count());
    }

    /** @test */
    public function chat_title_is_seeded_from_first_message_truncated_to_80_chars(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $long = str_repeat('x', 200);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => $long,
        ]);

        $response->assertStatus(202);

        $chat = Chat::findOrFail($response->json('chat.id'));
        $this->assertSame(80, mb_strlen($chat->title));
        $this->assertSame(str_repeat('x', 80), $chat->title);
    }

    /** @test */
    public function stream_url_matches_contract(): void
    {
        Bus::fake([ProcessChatMessageJob::class]);

        $company = $this->makeCompany('A');
        $user    = $this->makeUser($company);

        $response = $this->actingAs($user)->postJson('/api/chats/messages', [
            'scope_type' => Chat::SCOPE_GENERAL,
            'content'    => 'Hi',
        ]);

        $response->assertStatus(202);

        $chatId = $response->json('chat.id');
        $assistantId = $response->json('assistant_message.id');

        $this->assertSame(
            "/api/chats/{$chatId}/stream/{$assistantId}",
            $response->json('stream_url'),
        );
    }
}
