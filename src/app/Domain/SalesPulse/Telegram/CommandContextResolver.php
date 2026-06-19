<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Services\TestModeResolver;
use SergiX44\Nutgram\Nutgram;

/**
 * CommandContextResolver — turns the live Nutgram update into a CommandContext
 * (spec §8): resolve the chat → team, read the caller's TG username, compute the
 * admin flag, and tokenise the command arguments.
 *
 * Two resolution paths:
 *   1. The normal path: chat.id → TEAMS_JSON team (group chats, prod). Unchanged.
 *   2. The config-gated private-chat TEST MODE (TestModeResolver): when test mode is
 *      on AND the update is a PRIVATE chat AND the caller is a configured test admin,
 *      synthesise the "ТЕСТ" team (chat_id = this DM, caller = admin, roster = seeded
 *      test accounts). This NEVER affects real group traffic; off in prod.
 *
 * Argument tokenisation: nutgram's onCommand strips the command word, but to stay
 * robust across the {payload} vs raw-text forms we re-derive the tokens from the
 * message text — drop the leading "/command[@bot]" word and split the remainder on
 * whitespace.
 */
class CommandContextResolver
{
    public function __construct(
        private readonly TeamResolver $teams,
        private readonly TestModeResolver $testMode,
    ) {}

    public function resolve(Nutgram $bot): CommandContext
    {
        $callerTg = $bot->user()?->username;
        $args = $this->tokenizeArgs((string) ($bot->message()?->text ?? ''));

        // 1) Private-chat test mode takes precedence (config-gated, off in prod).
        $testTeam = $this->resolveTestTeam($bot, $callerTg);
        if ($testTeam instanceof Team) {
            return new CommandContext(
                team: $testTeam,
                callerTg: $callerTg,
                isAdmin: true, // the tester is the admin of their own test team
                args: $args,
                isTestMode: true,
            );
        }

        // 2) Normal path: chat → TEAMS_JSON team (group chats / prod).
        $team = $this->teams->teamByChat($bot->chatId());
        $isAdmin = $team !== null && $this->teams->isAdmin($team, $callerTg);

        return new CommandContext(
            team: $team,
            callerTg: $callerTg,
            isAdmin: $isAdmin,
            args: $args,
        );
    }

    /**
     * The synthesised "ТЕСТ" team when this update qualifies for test mode, else null.
     * A chat is "private" when chat.type is private OR chat.id == from.id (the
     * canonical DM signal — the two always agree in a real DM).
     */
    private function resolveTestTeam(Nutgram $bot, ?string $callerTg): ?Team
    {
        if (! $this->testMode->enabled()) {
            return null;
        }

        $chat = $bot->chat();
        $chatId = $bot->chatId();
        $fromId = $bot->userId();
        $isPrivate = ($chat?->isPrivate() ?? false)
            || ($chatId !== null && $fromId !== null && (int) $chatId === (int) $fromId);

        if (! $this->testMode->applies($isPrivate, $callerTg) || $chatId === null || $callerTg === null) {
            return null;
        }

        return $this->testMode->team($chatId, $callerTg);
    }

    /**
     * Split "/startday ilyarogov 2026-06-19" → ['ilyarogov', '2026-06-19'].
     * Drops the leading "/command" (with an optional @botname) word.
     *
     * @return list<string>
     */
    public function tokenizeArgs(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $text) ?: [];

        // Drop the command word itself when present.
        if (isset($parts[0]) && str_starts_with($parts[0], '/')) {
            array_shift($parts);
        }

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $t): bool => $t !== '',
        ));
    }
}
