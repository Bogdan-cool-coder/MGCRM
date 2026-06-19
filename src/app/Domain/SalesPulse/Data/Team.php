<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * Team — a decoded TEAMS_JSON entry (spec §8). Binds one Telegram chat to a set
 * of MGCRM sales pipelines, a manager roster and the admin usernames.
 *
 * Resolution helpers (port of the AMO bot's team model):
 *   - managerBySlug(): match a slug token to a manager by tg-username (case-
 *     insensitive), then by display name, then by numeric user_id (spec §8).
 *   - managerByTg(): the caller resolving to themselves by their TG username.
 *   - isAdmin(): username ∈ admins (case-insensitive).
 *
 * Pure value holder — no DB access (the User row is loaded by TeamResolver).
 */
final readonly class Team
{
    /**
     * @param  list<int>  $pipelineIds
     * @param  list<string>  $admins  Lower-cased tg usernames.
     * @param  list<TeamManager>  $managers
     */
    public function __construct(
        public string $chatId,
        public string $name,
        public array $pipelineIds,
        public array $admins,
        public array $managers,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $pipelineIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            (array) ($row['pipelines'] ?? []),
        ));

        $admins = array_values(array_map(
            static fn ($u): string => mb_strtolower(trim((string) $u)),
            (array) ($row['admins'] ?? []),
        ));

        $managers = array_values(array_map(
            static fn (array $m): TeamManager => TeamManager::fromArray($m),
            array_filter((array) ($row['managers'] ?? []), 'is_array'),
        ));

        return new self(
            chatId: (string) ($row['chat_id'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            pipelineIds: $pipelineIds,
            admins: $admins,
            managers: $managers,
        );
    }

    /**
     * Is the given TG username an admin of this team (case-insensitive, spec §8)?
     */
    public function isAdmin(?string $tgUsername): bool
    {
        if ($tgUsername === null || $tgUsername === '') {
            return false;
        }

        return in_array(mb_strtolower(trim($tgUsername)), $this->admins, true);
    }

    /**
     * Resolve a slug token to a roster entry (spec §8): tg-username (case-
     * insensitive) → display name (case-insensitive) → numeric user_id.
     */
    public function managerBySlug(string $slug): ?TeamManager
    {
        $needle = mb_strtolower(trim($slug));
        if ($needle === '') {
            return null;
        }

        foreach ($this->managers as $manager) {
            if ($manager->tg !== null && mb_strtolower($manager->tg) === $needle) {
                return $manager;
            }
        }

        foreach ($this->managers as $manager) {
            if (mb_strtolower($manager->name) === $needle) {
                return $manager;
            }
        }

        if (ctype_digit($needle)) {
            $id = (int) $needle;
            foreach ($this->managers as $manager) {
                if ($manager->userId === $id) {
                    return $manager;
                }
            }
        }

        return null;
    }

    /**
     * The roster entry whose tg-username matches the caller (case-insensitive).
     */
    public function managerByTg(?string $tgUsername): ?TeamManager
    {
        if ($tgUsername === null || $tgUsername === '') {
            return null;
        }

        $needle = mb_strtolower(trim($tgUsername));
        foreach ($this->managers as $manager) {
            if ($manager->tg !== null && mb_strtolower($manager->tg) === $needle) {
                return $manager;
            }
        }

        return null;
    }

    /**
     * @return list<int> The mgcrm user ids of every roster manager.
     */
    public function managerUserIds(): array
    {
        return array_values(array_map(
            static fn (TeamManager $m): int => $m->userId,
            $this->managers,
        ));
    }
}
