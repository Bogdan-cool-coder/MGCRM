<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Services\SkipService;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;

/**
 * SkipHandler — /skipday /unskipday /vacation /unvacation (admin, spec §8).
 *
 *   /skipday [менеджер] [дата]   — a day off. With a manager slug → personal; no
 *                                  slug → the whole team. Idempotent.
 *   /unskipday [менеджер] [дата] — remove the day off.
 *   /vacation менеджер [дата]    — vacation from today (or [дата]) until the given
 *                                  end date (2+ working days). Personal only.
 *   /unvacation менеджер [дата]  — clear the manager's vacation from [дата] onward.
 *
 * Date grammar reuses TeamResolver: single-date commands use parseArgs
 * ([date, slug]); /vacation uses parseDatesAndSlug so the start date, end date and
 * slug come from ONE consistent tokenisation.
 */
class SkipHandler
{
    use AdminGate;

    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly SkipService $skips,
    ) {}

    public function skipday(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$date, $slug] = $this->teams->parseArgs($ctx->args);
        $manager = $this->slugUser($ctx->team, $slug);

        $created = $this->skips->skipDay(
            date: $date,
            teamChatId: $ctx->team->chatId,
            manager: $manager,
            createdBy: $ctx->callerTg ?? 'admin',
        );

        $bot->sendMessage($created
            ? SalesPulseMessages::skipped($date, $manager !== null ? (string) $manager->full_name : null)
            : SalesPulseMessages::skipAlready($date));
    }

    public function unskipday(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$date, $slug] = $this->teams->parseArgs($ctx->args);
        $manager = $this->slugUser($ctx->team, $slug);

        $removed = $this->skips->unskipDay($date, $ctx->team->chatId, $manager);

        $bot->sendMessage($removed
            ? SalesPulseMessages::unskipped($date)
            : SalesPulseMessages::UNSKIP_NONE);
    }

    public function vacation(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        // ONE tokenisation: the first date is the start, the second is the end,
        // the first non-date token is the manager slug (spec §8). Two independent
        // scans could disagree on which token is the start vs the end.
        ['dates' => $dates, 'slug' => $slug] = $this->teams->parseDatesAndSlug($ctx->args);
        $manager = $this->slugUser($ctx->team, $slug);

        if ($manager === null) {
            $bot->sendMessage(SalesPulseMessages::MANAGER_NOT_FOUND);

            return;
        }

        $from = $dates[0] ?? null;
        $until = $dates[1] ?? null;
        if ($from === null || $until === null) {
            $bot->sendMessage(SalesPulseMessages::VACATION_TOO_SHORT);

            return;
        }

        if ($until->lessThan($from)) {
            $bot->sendMessage(SalesPulseMessages::VACATION_BAD_RANGE);

            return;
        }

        $days = $this->skips->vacation($from, $until, $manager, $ctx->callerTg ?? 'admin');

        if ($days < 2) {
            // Roll back the partial write — a vacation must be 2+ working days.
            $this->skips->unvacation($from, $manager);
            $bot->sendMessage(SalesPulseMessages::VACATION_TOO_SHORT);

            return;
        }

        $bot->sendMessage(SalesPulseMessages::vacationSet((string) $manager->full_name, $until, $days));
    }

    public function unvacation(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$from, $slug] = $this->teams->parseArgs($ctx->args);
        $manager = $this->slugUser($ctx->team, $slug);

        if ($manager === null) {
            $bot->sendMessage(SalesPulseMessages::MANAGER_NOT_FOUND);

            return;
        }

        $removed = $this->skips->unvacation($from, $manager);

        $bot->sendMessage($removed > 0
            ? SalesPulseMessages::vacationCleared((string) $manager->full_name, $removed)
            : SalesPulseMessages::VACATION_NONE);
    }

    /**
     * Resolve a slug to the MGCRM User, or null (a team-wide target).
     */
    private function slugUser(Team $team, ?string $slug): ?User
    {
        if ($slug === null) {
            return null;
        }

        $entry = $team->managerBySlug($slug);

        return $entry !== null ? $this->teams->userFor($entry) : null;
    }
}
