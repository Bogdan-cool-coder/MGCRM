<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use Carbon\CarbonImmutable;

/**
 * TeamResolver — the caller → team → manager resolution layer (spec §8).
 *
 * Source of truth: config('salespulse.teams') (decoded from SALESPULSE_TEAMS_JSON).
 * The resolver is the ONLY place that knows the team config shape; handlers ask it
 * "who is this chat / who is this caller / is this caller an admin" and get back
 * plain Team / TeamManager / User values.
 *
 *   - teamByChat(chatId): the team bound to a chat, or null (a foreign chat → the
 *     command is silently ignored, spec §8).
 *   - managerBySlug(team, slug): an admin acting for another manager.
 *   - callerManager(team, username): a manager acting for themselves.
 *   - userFor(teamManager): load the MGCRM User a roster entry points at.
 *   - isAdmin(team, username): admin-gating.
 *   - parseArgs(tokens): split a command's argument tokens into [date, slug] using
 *     the §8 grammar (today/yesterday + %Y-%m-%d / %d.%m.%Y / %d.%m.%y / %d.%m;
 *     the first non-date token is the manager slug).
 */
class TeamResolver
{
    /**
     * All configured teams, decoded once per resolve cycle.
     *
     * @return list<Team>
     */
    public function teams(): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) config('salespulse.teams', []);

        return array_values(array_map(
            static fn (array $row): Team => Team::fromArray($row),
            array_filter($raw, 'is_array'),
        ));
    }

    /**
     * The team bound to a Telegram chat id, or null when the chat is not
     * configured (foreign chat → command ignored, spec §8).
     */
    public function teamByChat(int|string|null $chatId): ?Team
    {
        if ($chatId === null) {
            return null;
        }

        $needle = (string) $chatId;
        foreach ($this->teams() as $team) {
            if ($team->chatId === $needle) {
                return $team;
            }
        }

        return null;
    }

    /**
     * Is the caller (by TG username) an admin of this team (spec §8)?
     */
    public function isAdmin(Team $team, ?string $tgUsername): bool
    {
        return $team->isAdmin($tgUsername);
    }

    /**
     * Resolve a manager-slug argument to a roster entry (admin acting for another).
     */
    public function managerBySlug(Team $team, string $slug): ?TeamManager
    {
        return $team->managerBySlug($slug);
    }

    /**
     * The roster entry for the caller resolving to themselves (by TG username).
     */
    public function callerManager(Team $team, ?string $tgUsername): ?TeamManager
    {
        return $team->managerByTg($tgUsername);
    }

    /**
     * Load the MGCRM User a roster entry points at (the snapshot services operate
     * on a real User). Null when the configured user_id no longer exists.
     */
    public function userFor(TeamManager $manager): ?User
    {
        return User::query()->find($manager->userId);
    }

    /**
     * Resolve the EFFECTIVE manager User for a /startday|/finishday call (spec §8):
     * an admin may target another manager via a slug; everyone else (and an admin
     * with no slug) acts for themselves. Returns null when the caller is neither a
     * roster manager nor an admin with a resolvable slug.
     *
     * @param  list<string>  $tokens  The raw command argument tokens.
     */
    public function resolveTargetUser(Team $team, ?string $callerTg, array $tokens): ?User
    {
        [, $slug] = $this->parseArgs($tokens);

        // Admin with an explicit slug → act for that manager.
        if ($slug !== null && $this->isAdmin($team, $callerTg)) {
            $entry = $this->managerBySlug($team, $slug);

            return $entry !== null ? $this->userFor($entry) : null;
        }

        // Otherwise act for the caller themselves.
        $entry = $this->callerManager($team, $callerTg);

        return $entry !== null ? $this->userFor($entry) : null;
    }

    /**
     * Split command argument tokens into a [date, slug] pair (spec §8):
     *   - today / yesterday keywords, or %Y-%m-%d / %d.%m.%Y / %d.%m.%y / %d.%m.
     *   - the FIRST token that is not a recognised date is taken as the manager slug.
     *
     * @param  list<string>  $tokens
     * @return array{0: CarbonImmutable, 1: ?string} [resolved date (defaults to today), slug or null]
     */
    public function parseArgs(array $tokens): array
    {
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $date = $today;
        $dateFound = false;
        $slug = null;

        foreach ($tokens as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }

            if (! $dateFound) {
                $parsed = $this->parseDateToken($token, $tz, $today);
                if ($parsed !== null) {
                    $date = $parsed;
                    $dateFound = true;

                    continue;
                }
            }

            if ($slug === null) {
                $slug = $token;
            }
        }

        return [$date, $slug];
    }

    /**
     * Parse a single date token (spec §8) or null when it is not a date.
     */
    public function parseDateToken(string $token, ?string $tz = null, ?CarbonImmutable $today = null): ?CarbonImmutable
    {
        $tz ??= $this->timezone();
        $today ??= CarbonImmutable::now($tz)->startOfDay();

        $lower = mb_strtolower($token);
        if ($lower === 'today') {
            return $today;
        }
        if ($lower === 'yesterday') {
            return $today->subDay();
        }

        foreach (['Y-m-d', 'd.m.Y', 'd.m.y', 'd.m'] as $fmt) {
            try {
                $dt = CarbonImmutable::createFromFormat($fmt, $token, $tz);
                if ($dt !== false) {
                    // %d.%m has no year → assume the current year.
                    if ($fmt === 'd.m') {
                        $dt = $dt->year($today->year);
                    }

                    return $dt->startOfDay();
                }
            } catch (\Throwable) {
                // try the next format
            }
        }

        return null;
    }

    private function timezone(): string
    {
        return (string) config('salespulse.timezone', 'Asia/Dubai');
    }
}
