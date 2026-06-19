<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\TeamManager;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Services\WeeklyAggregationService;
use App\Domain\SalesPulse\Services\WeeklyReportService;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use Carbon\CarbonImmutable;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * WeeklyReportHandler — /weeklyreport [понедельник] (admin, spec §5.2).
 *
 * Aggregate the working week (Monday of the given week, default = this week's
 * Monday) → WeeklyReportService renders TWO messages (report + narrative). Both
 * are sent in order.
 */
class WeeklyReportHandler
{
    use AdminGate;

    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly WeeklyAggregationService $aggregation,
        private readonly WeeklyReportService $report,
    ) {}

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$date] = $this->teams->parseArgs($ctx->args);
        $monday = $date->startOfWeek(CarbonImmutable::MONDAY);

        $managers = $this->loadManagers($ctx->team->managers);

        $data = $this->aggregation->aggregate(
            teamName: $ctx->team->name,
            managers: $managers,
            weekStart: $monday,
            pipelineIds: $ctx->team->pipelineIds,
            teamChatId: $ctx->team->chatId,
        );

        [$reportMessage, $narrativeMessage] = $this->report->render($data);

        $bot->sendMessage(text: $reportMessage, parse_mode: ParseMode::HTML);
        $bot->sendMessage(text: $narrativeMessage, parse_mode: ParseMode::HTML);
    }

    /**
     * @param  list<TeamManager>  $roster
     * @return list<User>
     */
    private function loadManagers(array $roster): array
    {
        $managers = [];
        foreach ($roster as $entry) {
            $user = $this->teams->userFor($entry);
            if ($user !== null) {
                $managers[] = $user;
            }
        }

        return $managers;
    }
}
