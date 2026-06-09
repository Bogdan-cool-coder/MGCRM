<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests for ChatMessage status helpers + the migration's default-value
 * behaviour. The contract these pin down is what M4 (ProcessChatMessageJob)
 * and ChatController rely on to detect "is there an in-flight assistant
 * message in this chat right now?".
 */
class ChatMessageStatusTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('activeStatuses')]
    public function test_is_active_returns_true_for_in_flight_statuses(string $status): void
    {
        $message = new ChatMessage(['status' => $status]);

        $this->assertTrue($message->isActive(), "status '{$status}' must be considered active");
    }

    #[DataProvider('terminalStatuses')]
    public function test_is_active_returns_false_for_terminal_statuses(string $status): void
    {
        $message = new ChatMessage(['status' => $status]);

        $this->assertFalse($message->isActive(), "status '{$status}' must NOT be considered active");
    }

    public static function activeStatuses(): array
    {
        return [
            'pending' => [ChatMessage::STATUS_PENDING],
            'running' => [ChatMessage::STATUS_RUNNING],
        ];
    }

    public static function terminalStatuses(): array
    {
        return [
            'done'      => [ChatMessage::STATUS_DONE],
            'error'     => [ChatMessage::STATUS_ERROR],
            'cancelled' => [ChatMessage::STATUS_CANCELLED],
        ];
    }

    public function test_status_constants_match_documented_values(): void
    {
        // Pinning the literal strings — these are part of the public API
        // (frontend reads them off /api/chats/{id}/messages) so an accidental
        // rename here is a breaking change.
        $this->assertSame('pending', ChatMessage::STATUS_PENDING);
        $this->assertSame('running', ChatMessage::STATUS_RUNNING);
        $this->assertSame('done', ChatMessage::STATUS_DONE);
        $this->assertSame('error', ChatMessage::STATUS_ERROR);
        $this->assertSame('cancelled', ChatMessage::STATUS_CANCELLED);

        $this->assertSame(
            ['pending', 'running', 'done', 'error', 'cancelled'],
            ChatMessage::STATUSES
        );
    }

    public function test_migration_default_stamps_existing_rows_as_done(): void
    {
        // Insert a row WITHOUT specifying status — the DB default should fill
        // it in. This is the contract that protects all pre-M2 ChatMessage
        // rows that existed before the migration ran.
        $company = \App\Models\Company::create(['name' => 'Default Co']);
        $user    = \App\Models\User::forceCreate([
            'name'       => 'Default Tester',
            'email'      => 'default+'.uniqid().'@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
        ]);
        $chat = \App\Models\Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'quick_qa',
        ]);

        // Bypass model fillable to truly NOT set status — mimics a legacy row.
        $messageId = \Illuminate\Support\Facades\DB::table('chat_messages')->insertGetId([
            'chat_id'    => $chat->id,
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'role'       => 'user',
            'content'    => 'legacy message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reloaded = ChatMessage::find($messageId);

        $this->assertSame(ChatMessage::STATUS_DONE, $reloaded->status);
        $this->assertFalse($reloaded->isActive());
    }

    public function test_started_at_and_finished_at_are_cast_to_carbon(): void
    {
        $company = \App\Models\Company::create(['name' => 'Carbon Cast Co']);
        $user    = \App\Models\User::forceCreate([
            'name'       => 'Carbon Tester',
            'email'      => 'carbon+'.uniqid().'@example.com',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'analyst',
        ]);
        $chat = \App\Models\Chat::create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => 'report_generation',
        ]);

        $message = ChatMessage::create([
            'chat_id'     => $chat->id,
            'user_id'     => $user->id,
            'company_id'  => $company->id,
            'role'        => 'assistant',
            'content'     => '',
            'status'      => ChatMessage::STATUS_RUNNING,
            'started_at'  => '2026-05-20 10:00:00',
            'finished_at' => null,
        ]);

        $fresh = ChatMessage::find($message->id);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->started_at);
        $this->assertNull($fresh->finished_at);
    }
}
