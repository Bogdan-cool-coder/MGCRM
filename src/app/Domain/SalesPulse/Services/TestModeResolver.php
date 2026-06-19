<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use Illuminate\Support\Facades\Log;

/**
 * TestModeResolver — the config-gated private-chat TEST MODE (config('salespulse.
 * test_mode')). A dev/QA convenience so the bot logic can be driven in a 1-on-1 DM
 * with the test bot, on the seeded test accounts, without a configured group chat.
 *
 * In a private chat the Telegram chat.id equals the user's own id and is never in
 * TEAMS_JSON, so the normal TeamResolver yields no team and the command is silently
 * ignored. This resolver adds the narrow second path:
 *
 *   enabled && the message is a PRIVATE chat && from.username ∈ test_mode.admins
 *     → synthesise the "ТЕСТ" Team whose chat_id is THIS private chat (so replies
 *       and any posts land in the tester's DM), whose ONLY admin is the tester (full
 *       access incl. admin-only commands), and whose roster is the seeded test
 *       accounts (resolved by email → user_id; a missing account is skipped + logged).
 *
 * This NEVER affects real traffic: group chats resolve through TEAMS_JSON exactly as
 * before, and a private chat from a non-admin (or with the flag off) gets no team.
 * In prod SALESPULSE_TEST_MODE=false and every method below short-circuits.
 */
class TestModeResolver
{
    /** Marker for "every active sales pipeline" in test_mode.team.pipelines. */
    private const ALL_ACTIVE_SALES = 'all_active_sales';

    public function enabled(): bool
    {
        return (bool) config('salespulse.test_mode.enabled', false);
    }

    /**
     * Is the given TG username one of the configured test-mode admins
     * (case-insensitive)? False when test mode is off or the username is empty.
     */
    public function isTestAdmin(?string $tgUsername): bool
    {
        if (! $this->enabled() || $tgUsername === null || $tgUsername === '') {
            return false;
        }

        $needle = mb_strtolower(trim($tgUsername));

        foreach ($this->admins() as $admin) {
            if (mb_strtolower(trim($admin)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this update qualify for the test-mode path: test mode is on, the chat is
     * private (chat.id == from.id is the canonical private-chat signal), and the
     * caller is a configured test admin?
     */
    public function applies(bool $isPrivateChat, ?string $tgUsername): bool
    {
        return $this->enabled() && $isPrivateChat && $this->isTestAdmin($tgUsername);
    }

    /**
     * Build the synthetic "ТЕСТ" Team for a tester's DM. The chat_id is the private
     * chat id (the tester's own id) so replies go to their DM; the caller's username
     * is the sole admin; the roster is the seeded test accounts resolved by email.
     * A roster entry whose email has no live User is dropped with a log line.
     */
    public function team(int|string $privateChatId, string $adminUsername): Team
    {
        return new Team(
            chatId: (string) $privateChatId,
            name: (string) config('salespulse.test_mode.team.name', 'ТЕСТ'),
            pipelineIds: $this->resolvePipelineIds(),
            admins: [mb_strtolower(trim($adminUsername))],
            managers: $this->resolveManagers(),
        );
    }

    /**
     * The configured test-mode admin usernames (raw, not lower-cased).
     *
     * @return list<string>
     */
    public function admins(): array
    {
        /** @var array<int, mixed> $raw */
        $raw = (array) config('salespulse.test_mode.admins', []);

        return array_values(array_filter(
            array_map(static fn ($u): string => (string) $u, $raw),
            static fn (string $u): bool => trim($u) !== '',
        ));
    }

    /**
     * Resolve the test team's roster (config('salespulse.test_mode.team.managers'))
     * to TeamManager DTOs. Each entry's `email` is resolved to a live user_id; an
     * account that does not exist is skipped with a log line (so a partial seed still
     * produces a usable team).
     *
     * @return list<TeamManager>
     */
    public function resolveManagers(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) config('salespulse.test_mode.team.managers', []);

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $email = (string) ($row['email'] ?? '');
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : null;

            if ($user === null) {
                Log::info('SalesPulse test mode: skipping unseeded test manager.', [
                    'email' => $email,
                ]);

                continue;
            }

            $tg = isset($row['tg']) && $row['tg'] !== '' ? (string) $row['tg'] : null;

            $out[] = new TeamManager(
                userId: (int) $user->id,
                tg: $tg,
                name: (string) ($row['name'] ?? $user->full_name ?? $email),
            );
        }

        return $out;
    }

    /**
     * Resolve test_mode.team.pipelines (a list of canonical pipeline NAMES, or the
     * single marker 'all_active_sales') to MGCRM pipeline ids. Unknown names resolve
     * to nothing; an empty result falls back to every active sales pipeline so the
     * test team is never funnel-less.
     *
     * @return list<int>
     */
    public function resolvePipelineIds(): array
    {
        /** @var array<int, mixed> $config */
        $config = (array) config('salespulse.test_mode.team.pipelines', []);
        $names = array_values(array_map(static fn ($n): string => (string) $n, $config));

        if (in_array(self::ALL_ACTIVE_SALES, $names, true) || $names === []) {
            return $this->allActiveSalesPipelineIds();
        }

        $ids = Pipeline::query()
            ->sales()
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            // Configured names matched no funnel (e.g. AMO funnels not seeded yet) —
            // fall back to every active sales pipeline rather than an empty team.
            return $this->allActiveSalesPipelineIds();
        }

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    private function allActiveSalesPipelineIds(): array
    {
        return array_values(
            Pipeline::query()
                ->sales()
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );
    }
}
