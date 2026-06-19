<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use App\Domain\SalesPulse\Telegram\Handlers\AnnounceHandler;
use App\Domain\SalesPulse\Telegram\Handlers\ConversionsHandler;
use App\Domain\SalesPulse\Telegram\Handlers\DayResultsHandler;
use App\Domain\SalesPulse\Telegram\Handlers\FinishdayHandler;
use App\Domain\SalesPulse\Telegram\Handlers\InfoHandler;
use App\Domain\SalesPulse\Telegram\Handlers\ProgressHandler;
use App\Domain\SalesPulse\Telegram\Handlers\SkipHandler;
use App\Domain\SalesPulse\Telegram\Handlers\StartdayHandler;
use App\Domain\SalesPulse\Telegram\Handlers\WeeklyReportHandler;
use Illuminate\Contracts\Container\Container;
use SergiX44\Nutgram\Nutgram;

/**
 * SalesPulseBot — the single registration point for the SalesPulse bot's command
 * handlers. Shared by the `salespulse:run` command (long-polling) and the test
 * suite (a FakeNutgram), so the handler set is identical in both.
 *
 * Handlers are resolved from the container (constructor DI) and bound as
 * [$instance, 'method'] callables. Registration is pure binding — long-polling
 * (getUpdates) is the caller's concern and runs in EXACTLY ONE process per token
 * (the `salespulse-bot` container).
 */
class SalesPulseBot
{
    /** Container binding name for the SalesPulse Nutgram singleton. */
    public const BINDING = 'salespulse.bot';

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Bind every SalesPulse command handler onto the given Nutgram instance.
     */
    public function register(Nutgram $bot): void
    {
        $info = $this->container->make(InfoHandler::class);

        // Anyone in a bound chat.
        $bot->onCommand('start', [$info, 'start'])->description('Запуск бота');
        $bot->onCommand('help', [$info, 'help'])->description('Список команд');
        $bot->onCommand('whoami', [$info, 'whoami'])->description('Кто я для бота');

        // Arg-taking commands: nutgram's onCommand matches ONLY the bare command
        // (trailing text → no match), so we register each one twice — bare and with
        // a greedy "rest of line" pattern (`{args}` constrained to `.+`). Handlers
        // re-tokenise from the raw message text, so the captured value is unused.
        $this->bind($bot, 'progress', $this->container->make(ProgressHandler::class), 'Текущая активность команды');
        $this->bind($bot, 'startday', $this->container->make(StartdayHandler::class), 'Зафиксировать план дня');
        $this->bind($bot, 'finishday', $this->container->make(FinishdayHandler::class), 'Зафиксировать факт дня');
        $this->bind($bot, 'dayresults', $this->container->make(DayResultsHandler::class), 'Разбор дня (админ)');
        $this->bind($bot, 'weeklyreport', $this->container->make(WeeklyReportHandler::class), 'Недельный отчёт (админ)');
        $this->bind($bot, 'conversions', $this->container->make(ConversionsHandler::class), 'Воронка конверсий (админ)');

        $bot->onCommand('announce_now', $this->container->make(AnnounceHandler::class))
            ->description('Ручной анонс (админ)');

        $skip = $this->container->make(SkipHandler::class);
        $this->bind($bot, 'skipday', [$skip, 'skipday'], 'Пропуск дня (админ)');
        $this->bind($bot, 'unskipday', [$skip, 'unskipday'], 'Снять пропуск (админ)');
        $this->bind($bot, 'vacation', [$skip, 'vacation'], 'Отпуск менеджера (админ)');
        $this->bind($bot, 'unvacation', [$skip, 'unvacation'], 'Снять отпуск (админ)');
    }

    /**
     * Register an arg-taking command in BOTH forms so it matches the bare command
     * and the command-with-arguments. The `{args}` parameter is constrained to
     * `.+` (the rest of the line); handlers ignore the captured value and tokenise
     * the raw message text themselves.
     *
     * @param  callable|object  $handler
     */
    private function bind(Nutgram $bot, string $command, $handler, string $description): void
    {
        $bot->onCommand($command, $handler)->description($description);
        $bot->onCommand($command.' {args}', $handler)
            ->where('args', '.+')
            ->description($description);
    }
}
