<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * TeamManager — one roster entry from a team's TEAMS_JSON config (spec §8):
 *   { "user_id": <mgcrm user id>, "tg": "ilyarogov", "name": "Илья Рогов" }
 *
 * `userId` links to the MGCRM User the snapshot services operate on; `tg` is the
 * Telegram username used to resolve a caller to themselves and to @-mention them;
 * `name` is the display name (used when the User row is unavailable / for slugs).
 */
final readonly class TeamManager
{
    public function __construct(
        public int $userId,
        public ?string $tg,
        public string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $tg = isset($row['tg']) && $row['tg'] !== '' ? (string) $row['tg'] : null;

        return new self(
            userId: (int) ($row['user_id'] ?? 0),
            tg: $tg,
            name: (string) ($row['name'] ?? ''),
        );
    }
}
