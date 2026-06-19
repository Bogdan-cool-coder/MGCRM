<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Data\ProgressLine;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use App\Domain\SalesPulse\Renderers\ProgressRenderer;
use App\Domain\SalesPulse\Services\ProgressService;
use App\Domain\SalesPulse\Services\SkipService;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use Carbon\CarbonImmutable;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * ProgressHandler — /progress [дата] (spec §6.1). Available to anyone in a bound
 * chat. Builds one ProgressLine per roster manager (vacation / skip / no-plan /
 * live), then renders the team block. Label = "HH:MM" for an on-demand call.
 */
class ProgressHandler
{
    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly ProgressService $progress,
        private readonly SkipService $skips,
        private readonly ProgressRenderer $renderer,
    ) {}

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $ctx->hasTeam()) {
            return;
        }

        [$date] = $this->teams->parseArgs($ctx->args);

        $lines = $this->buildLines($ctx->team, $date);
        $label = CarbonImmutable::now((string) config('salespulse.timezone', 'Asia/Dubai'))->format('H:i');

        $bot->sendMessage(
            text: $this->renderer->render($ctx->team->name, $date, $label, $lines),
            parse_mode: ParseMode::HTML,
        );
    }

    /**
     * @return list<ProgressLine>
     */
    private function buildLines(Team $team, CarbonImmutable $date): array
    {
        $lines = [];

        foreach ($team->managers as $entry) {
            $user = $this->teams->userFor($entry);
            if ($user === null) {
                continue; // configured user_id no longer exists
            }

            $vacationUntil = $this->skips->vacationUntil($date, $user);

            $lines[] = $this->progress->lineFor(
                manager: $user,
                date: $date,
                nameLink: $this->nameLink($entry),
                teamChatId: $team->chatId,
                vacationUntil: $vacationUntil?->format('d.m'),
            );
        }

        return $lines;
    }

    /**
     * Display name for the progress line. Slice 4 may wire a real deep link; today
     * the manager's name is the link text (the bot never leaks an internal id).
     */
    private function nameLink(TeamManager $entry): string
    {
        return $entry->name;
    }
}
