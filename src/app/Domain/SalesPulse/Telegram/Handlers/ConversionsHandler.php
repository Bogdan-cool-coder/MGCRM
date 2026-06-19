<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Renderers\ConversionsRenderer;
use App\Domain\SalesPulse\Services\ConversionsService;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * ConversionsHandler — /conversions [N|дата…] (admin, spec §6.2).
 *
 * Parse the period from the args (no arg → 30 days; N → N days; one ISO date → from
 * that date; two dates → range), analyse the team's PLAN-snapshot trajectories,
 * render the funnel block.
 */
class ConversionsHandler
{
    use AdminGate;

    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly ConversionsService $conversions,
        private readonly ConversionsRenderer $renderer,
    ) {}

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$from, $to] = $this->conversions->parsePeriod($ctx->args);

        $data = $this->conversions->analyze(
            managerIds: $ctx->team->managerUserIds(),
            pipelineIds: $ctx->team->pipelineIds,
            from: $from,
            to: $to,
        );

        $bot->sendMessage(text: $this->renderer->render($data), parse_mode: ParseMode::HTML);
    }
}
