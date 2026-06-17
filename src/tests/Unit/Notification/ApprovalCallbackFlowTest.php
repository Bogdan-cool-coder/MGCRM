<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ApprovalService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Notification\Models\TelegramLinkToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApprovalCallbackFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('crm.telegram.web_base_url', 'https://crm.test');
        config()->set('crm.telegram.approval_chat_id', '-100999');
        config()->set('crm.telegram.bot_username', 'macro_test_bot');
    }

    /** Build the faked bot (handlers auto-loaded from routes/telegram.php) acting as $tgId. */
    private function bot(int $tgId): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);
        $bot->setCommonUser(TgUser::make($tgId, false, 'Tester'));
        $bot->setCommonChat(Chat::make($tgId, ChatType::PRIVATE));

        return $bot;
    }

    /** Assert that SOME request in the bot history calls $method with a body containing $needle. */
    private function assertSent(FakeNutgram $bot, string $method, string $needle): void
    {
        foreach ($bot->getRequestHistory() as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            if (! str_ends_with($request->getUri()->getPath(), $method)) {
                continue;
            }
            $body = urldecode((string) $request->getBody());
            if (str_contains($body, $needle)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("No {$method} request containing \"{$needle}\" was sent.");
    }

    /** Assert that no request in history called $method. */
    private function assertNotSentMethod(FakeNutgram $bot, string $method): void
    {
        foreach ($bot->getRequestHistory() as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            if (str_ends_with($request->getUri()->getPath(), $method)) {
                $this->fail("Unexpected {$method} request was sent.");
            }
        }

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // /start linking
    // -------------------------------------------------------------------------

    public function test_start_link_redeems_token_and_links_user(): void
    {
        $user = User::factory()->create(['full_name' => 'Иван Петров', 'telegram_user_id' => null]);
        $token = TelegramLinkToken::factory()->create(['user_id' => $user->id]);

        $bot = $this->bot(700700);
        $bot->hearText('/start link_'.$token->token)->reply();

        $this->assertSent($bot, 'sendMessage', 'Telegram привязан к учётной записи');
        $this->assertSame('700700', $user->fresh()->telegram_user_id);
    }

    public function test_start_invalid_token_replies_error(): void
    {
        $bot = $this->bot(700700);
        $bot->hearText('/start link_nope')->reply();

        $this->assertSent($bot, 'sendMessage', 'Ссылка недействительна');
    }

    public function test_start_unlinked_user_gets_instructions(): void
    {
        $bot = $this->bot(700700);
        $bot->hearText('/start')->reply();

        $this->assertSent($bot, 'sendMessage', 'Привязать Telegram');
    }

    public function test_start_linked_user_gets_greeting_with_role(): void
    {
        User::factory()->create([
            'full_name' => 'Иван Петров',
            'role' => Role::Lawyer,
            'telegram_user_id' => '700700',
        ]);

        $bot = $this->bot(700700);
        $bot->hearText('/start')->reply();

        $this->assertSent($bot, 'sendMessage', 'Роль: Юрист');
    }

    // -------------------------------------------------------------------------
    // Callback — unlinked / missing
    // -------------------------------------------------------------------------

    public function test_unlinked_user_gets_alert(): void
    {
        $spy = Mockery::spy(ApprovalService::class);
        $this->app->instance(ApprovalService::class, $spy);

        $document = Document::factory()->inReview()->create();

        $bot = $this->bot(999999);
        $bot->hearCallbackQueryData('apv:approve:'.$document->id)->reply();

        $this->assertSent($bot, 'answerCallbackQuery', 'не привязан');
        $spy->shouldNotHaveReceived('decide');
    }

    public function test_document_not_found_gets_alert(): void
    {
        User::factory()->create(['telegram_user_id' => '700700']);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:approve:99999')->reply();

        $this->assertSent($bot, 'answerCallbackQuery', 'не найден');
    }

    // -------------------------------------------------------------------------
    // approve (no comment)
    // -------------------------------------------------------------------------

    public function test_approve_calls_decide_and_removes_markup(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $mock = Mockery::mock(ApprovalService::class);
        $mock->shouldReceive('decide')
            ->once()
            ->withArgs(fn ($doc, $u, $decision, $comment) => $decision === ApprovalDecision::Approved && $comment === null)
            ->andReturnUsing(function () use ($document) {
                $document->status = ContractStatus::Approved;

                return $document;
            });
        $this->app->instance(ApprovalService::class, $mock);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:approve:'.$document->id)->reply();

        $bot->assertCalled('answerCallbackQuery');
        $bot->assertCalled('editMessageReplyMarkup');
        $this->assertSent($bot, 'sendMessage', 'согласовал');
        $this->assertSent($bot, 'sendMessage', 'полностью согласован');
    }

    public function test_double_approve_is_polite_409(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $mock = Mockery::mock(ApprovalService::class);
        $mock->shouldReceive('decide')
            ->once()
            ->andThrow(new HttpException(409, 'Документ не находится на согласовании.'));
        $this->app->instance(ApprovalService::class, $mock);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:approve:'.$document->id)->reply();

        $this->assertSent($bot, 'answerCallbackQuery', 'уже обработан');
    }

    public function test_not_assigned_approver_gets_403_alert(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $mock = Mockery::mock(ApprovalService::class);
        $mock->shouldReceive('decide')
            ->once()
            ->andThrow(new HttpException(403, 'Вы не назначены согласователем на текущем этапе.'));
        $this->app->instance(ApprovalService::class, $mock);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:approve:'.$document->id)->reply();

        $this->assertSent($bot, 'answerCallbackQuery', 'не назначены');
    }

    // -------------------------------------------------------------------------
    // reject / rework (conversation)
    // -------------------------------------------------------------------------

    public function test_reject_starts_conversation_without_deciding(): void
    {
        $spy = Mockery::spy(ApprovalService::class);
        $this->app->instance(ApprovalService::class, $spy);

        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:reject:'.$document->id)
            ->reply()
            ->assertActiveConversation(userId: 700700, chatId: 700700);

        $this->assertSent($bot, 'sendMessage', 'укажите причину отклонения');
        $spy->shouldNotHaveReceived('decide');
    }

    public function test_reject_conversation_collects_reason_and_decides(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $mock = Mockery::mock(ApprovalService::class);
        $mock->shouldReceive('decide')
            ->once()
            ->withArgs(fn ($doc, $u, $decision, $comment) => $decision === ApprovalDecision::Rejected && $comment === 'Неверные реквизиты')
            ->andReturn($document);
        $this->app->instance(ApprovalService::class, $mock);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:reject:'.$document->id)->reply();

        $bot->hearText('Неверные реквизиты')
            ->reply()
            ->assertNoConversation(userId: 700700, chatId: 700700);

        $this->assertSent($bot, 'sendMessage', 'Причина сохранена');
    }

    public function test_empty_reason_reprompts_and_keeps_conversation(): void
    {
        $spy = Mockery::spy(ApprovalService::class);
        $this->app->instance(ApprovalService::class, $spy);

        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:rework:'.$document->id)->reply();

        $bot->hearText('   ')
            ->reply()
            ->assertActiveConversation(userId: 700700, chatId: 700700);

        $this->assertSent($bot, 'sendMessage', 'Причина не может быть пустой');
        $spy->shouldNotHaveReceived('decide');
    }

    public function test_rework_decision_uses_needs_rework(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'telegram_user_id' => '700700']);
        $document = Document::factory()->inReview()->create();

        $mock = Mockery::mock(ApprovalService::class);
        $mock->shouldReceive('decide')
            ->once()
            ->withArgs(fn ($doc, $u, $decision, $comment) => $decision === ApprovalDecision::NeedsRework && $comment === 'Доработать пункт 3')
            ->andReturn($document);
        $this->app->instance(ApprovalService::class, $mock);

        $bot = $this->bot(700700);
        $bot->hearCallbackQueryData('apv:rework:'.$document->id)->reply();
        $bot->hearText('Доработать пункт 3')->reply();

        $this->assertSent($bot, 'sendMessage', 'Причина сохранена');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
