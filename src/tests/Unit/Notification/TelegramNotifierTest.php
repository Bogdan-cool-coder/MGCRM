<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Iam\Models\User;
use App\Domain\Notification\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_chat_returns_message_id(): void
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        // Mock the Bot API reply for sendMessage with a known message_id.
        $bot->willReceive([
            'message_id' => 4242,
            'date' => 1700000000,
            'chat' => ['id' => -100999, 'type' => 'group'],
        ]);

        $notifier = new TelegramNotifier($bot);
        $messageId = $notifier->sendToChat('-100999', 'hello');

        $this->assertSame(4242, $messageId);
        $bot->assertCalled('sendMessage');
    }

    public function test_send_to_user_returns_false_when_not_linked(): void
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $notifier = new TelegramNotifier($bot);

        $user = User::factory()->create(['telegram_user_id' => null]);

        $this->assertFalse($notifier->sendToUser($user, 'hi'));
        $bot->assertCalled('sendMessage', times: 0);
    }

    public function test_send_to_user_sends_when_linked(): void
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->willReceive([
            'message_id' => 1,
            'date' => 1700000000,
            'chat' => ['id' => 700700, 'type' => 'private'],
        ]);

        $notifier = new TelegramNotifier($bot);
        $user = User::factory()->create(['telegram_user_id' => '700700']);

        $this->assertTrue($notifier->sendToUser($user, 'hi'));
        $bot->assertCalled('sendMessage');
    }
}
