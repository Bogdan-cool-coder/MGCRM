<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\SalesPulse\Telegram\SalesPulseBot;
use Psr\Http\Message\RequestInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TgUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * Shared FakeNutgram harness for the SalesPulse bot Slice 3 suite.
 *
 * The SalesPulse bot is a SECOND, separate instance (not the contract bot's
 * nutgram/laravel singleton), so the handlers are NOT auto-loaded — we build a
 * FakeNutgram and register them via SalesPulseBot::register(), exactly as
 * `salespulse:run` does in production. Asserts scan the Guzzle request history for
 * a sendMessage carrying a needle (robust to call order), per the project's
 * nutgram testing pattern.
 */
trait SalesPulseBotTestSupport
{
    /**
     * Build a faked SalesPulse bot acting as a given chat + TG user, with all
     * SalesPulse handlers registered.
     */
    private function pulseBot(int|string $chatId, int $tgUserId, ?string $username = null, string $firstName = 'Tester'): FakeNutgram
    {
        $bot = FakeNutgram::instance();

        $tgUser = TgUser::make($tgUserId, false, $firstName);
        $tgUser->username = $username;

        $bot->setCommonUser($tgUser);
        $bot->setCommonChat(Chat::make((int) $chatId, ChatType::GROUP));

        app(SalesPulseBot::class)->register($bot);

        return $bot;
    }

    /**
     * Build a faked SalesPulse bot acting in a PRIVATE chat (a DM): chat.id equals
     * the TG user id and chat.type is private — the shape the private-chat test mode
     * keys off. All SalesPulse handlers are registered.
     */
    private function privatePulseBot(int $tgUserId, ?string $username = null, string $firstName = 'Tester'): FakeNutgram
    {
        $bot = FakeNutgram::instance();

        $tgUser = TgUser::make($tgUserId, false, $firstName);
        $tgUser->username = $username;

        $bot->setCommonUser($tgUser);
        $bot->setCommonChat(Chat::make($tgUserId, ChatType::PRIVATE));

        app(SalesPulseBot::class)->register($bot);

        return $bot;
    }

    /** Dispatch a text message (a command) through the faked bot. */
    private function sendText(FakeNutgram $bot, string $text): void
    {
        $bot->hearText($text)->reply();
    }

    /** Assert SOME sendMessage in history contains $needle. */
    private function assertSentText(Nutgram $bot, string $needle): void
    {
        foreach ($this->sentMessages($bot) as $body) {
            if (str_contains($body, $needle)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("No sendMessage containing \"{$needle}\" was sent.");
    }

    /** Assert NO sendMessage in history contains $needle. */
    private function assertNotSentText(Nutgram $bot, string $needle): void
    {
        foreach ($this->sentMessages($bot) as $body) {
            if (str_contains($body, $needle)) {
                $this->fail("Unexpected sendMessage containing \"{$needle}\".");
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Decoded bodies of every sendMessage request in the bot history.
     *
     * @return list<string>
     */
    private function sentMessages(Nutgram $bot): array
    {
        $out = [];
        foreach ($bot->getRequestHistory() as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            if (! str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $out[] = urldecode((string) $request->getBody());
        }

        return $out;
    }

    /** Count of sendMessage requests in history. */
    private function sendMessageCount(Nutgram $bot): int
    {
        return count($this->sentMessages($bot));
    }

    /**
     * Configure config('salespulse.teams') with one team.
     *
     * @param  list<int>  $pipelineIds
     * @param  list<string>  $admins
     * @param  list<array{user_id:int,tg:?string,name:string}>  $managers
     */
    private function configureTeam(string $chatId, array $pipelineIds, array $admins, array $managers, string $name = 'MACRO Global'): void
    {
        config()->set('salespulse.teams', [[
            'chat_id' => $chatId,
            'name' => $name,
            'pipelines' => $pipelineIds,
            'admins' => $admins,
            'managers' => $managers,
        ]]);
    }
}
