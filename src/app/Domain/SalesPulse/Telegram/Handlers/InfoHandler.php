<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * InfoHandler — /start, /help, /whoami (spec §8: available to anyone in a bound
 * chat). A foreign chat (not in TEAMS_JSON) is silently ignored.
 */
class InfoHandler
{
    public function __construct(
        private readonly CommandContextResolver $resolver,
    ) {}

    public function start(Nutgram $bot): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $ctx->hasTeam()) {
            return; // foreign chat (or DM with test mode off / non-admin) → ignore
        }

        if ($ctx->isTestMode) {
            $bot->sendMessage(
                text: SalesPulseMessages::testModeIntro($ctx->team->name, $this->managerSlugs($ctx->team)),
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $bot->sendMessage(SalesPulseMessages::START);
    }

    public function help(Nutgram $bot): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $ctx->hasTeam()) {
            return;
        }

        $bot->sendMessage(SalesPulseMessages::HELP);
    }

    public function whoami(Nutgram $bot): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $ctx->hasTeam()) {
            return;
        }

        if ($ctx->isTestMode) {
            $bot->sendMessage(
                text: SalesPulseMessages::testModeIntro($ctx->team->name, $this->managerSlugs($ctx->team)),
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $manager = $ctx->team->managerByTg($ctx->callerTg);

        $bot->sendMessage(
            text: SalesPulseMessages::whoami(
                $ctx->team->name,
                $ctx->isAdmin,
                $manager?->name,
            ),
            parse_mode: ParseMode::HTML,
        );
    }

    /**
     * The roster tg-slugs that resolved to a seeded account (for the test-mode DM
     * onboarding copy). Falls back to the display name when a manager has no slug.
     *
     * @return list<string>
     */
    private function managerSlugs(Team $team): array
    {
        return array_values(array_map(
            static fn (TeamManager $m): string => $m->tg ?? $m->name,
            $team->managers,
        ));
    }
}
