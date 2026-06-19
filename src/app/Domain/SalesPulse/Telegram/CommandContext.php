<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use App\Domain\SalesPulse\Data\Team;

/**
 * CommandContext — the resolved per-message envelope a SalesPulse handler works
 * with: the team bound to the chat, the caller's TG username, admin flag, and the
 * tokenised command arguments (everything after the command word).
 *
 * Built once per message by CommandContextResolver. When `team` is null the chat
 * is not configured and the command is silently ignored (spec §8). `isTestMode` is
 * true only for the synthetic private-chat "ТЕСТ" team (config-gated, off in prod) —
 * handlers use it purely for the DM onboarding copy; command behaviour is identical.
 */
final readonly class CommandContext
{
    /**
     * @param  list<string>  $args  Argument tokens after the command word.
     */
    public function __construct(
        public ?Team $team,
        public ?string $callerTg,
        public bool $isAdmin,
        public array $args,
        public bool $isTestMode = false,
    ) {}

    public function hasTeam(): bool
    {
        return $this->team instanceof Team;
    }
}
