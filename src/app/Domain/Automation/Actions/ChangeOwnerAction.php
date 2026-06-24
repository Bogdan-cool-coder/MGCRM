<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\DB;

/**
 * change_owner — reassign the deal owner by a routing rule.
 *
 * MVP rule: round_robin. The candidate pool is the active users matching the
 * config filter (role / department), ordered by id for a stable rotation. The
 * cursor is persisted on pipeline_automations.round_robin_cursor; the next pick
 * is pool[cursor % count] and the cursor is then advanced.
 *
 * Concurrency: under scale-out workers two ticks could read the same cursor and
 * pick the same owner. A per-automation PG advisory lock (pg_advisory_xact_lock)
 * serialises the read→pick→advance section for the duration of the transaction.
 * On SQLite (tests) the lock is a no-op — single-connection, no race.
 *
 * After the move, department_id is re-synced from the new owner so the
 * visibility scope and KPI snapshots stay consistent.
 *
 * The candidate pool resolves from two mutually-supported config shapes:
 *   - pool: int[]            — an explicit, hand-picked set of user ids (the
 *                              builder UI emits this from its user MultiSelect).
 *   - user_pool_filter: {role?, department?} — a dynamic filter (API/legacy).
 * `pool` takes precedence when present; otherwise the filter applies; when
 * neither is given the pool is every active user. Either way the result is the
 * active users only, ordered by id for a stable rotation.
 *
 * config: {
 *   rule?: "round_robin",
 *   pool?: int[],
 *   user_pool_filter?: { role?, department? }
 * }
 */
final class ChangeOwnerAction implements ActionHandler
{
    public function kind(): ActionKind
    {
        return ActionKind::ChangeOwner;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $rule = (string) ($config['rule'] ?? 'round_robin');
        if ($rule !== 'round_robin') {
            return ActionResult::skipped("Unsupported change_owner rule: {$rule}");
        }

        $pool = $this->pool($config);
        if ($pool === []) {
            return ActionResult::skipped('Candidate pool is empty.');
        }

        return DB::transaction(function () use ($automation, $target, $pool): ActionResult {
            $this->acquireLock($automation->id);

            // Re-read the cursor under the lock so concurrent ticks serialise.
            $locked = PipelineAutomation::query()->lockForUpdate()->find($automation->id);
            $cursor = (int) ($locked->round_robin_cursor ?? 0);

            $picked = $pool[$cursor % count($pool)];
            $old = $target->owner_user_id;

            // No-op guard: the cursor already points at the current owner. Skip the
            // write entirely — re-stamping department_id and advancing the cursor on
            // a no-op churns state for nothing (and would shuffle the rotation for a
            // single-user pool). The cursor is left in place so the next *real*
            // re-assignment still lands on this position.
            if ((int) $old === (int) $picked) {
                return ActionResult::skipped("Owner is already user {$picked}; nothing to reassign.", [
                    'rule' => 'round_robin',
                    'owner' => $picked,
                    'pool_size' => count($pool),
                ]);
            }

            $nextCursor = ($cursor + 1) % count($pool);
            $locked->update(['round_robin_cursor' => $nextCursor]);

            $departmentId = User::query()->whereKey($picked)->value('department_id');
            $target->update([
                'owner_user_id' => $picked,
                'department_id' => $departmentId,
            ]);

            return ActionResult::success("Reassigned owner to user {$picked}", [
                'rule' => 'round_robin',
                'old' => $old,
                'new' => $picked,
                'pool_size' => count($pool),
            ]);
        });
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $rule = (string) ($config['rule'] ?? 'round_robin');
        if ($rule !== 'round_robin') {
            return ActionPreview::wont("Unsupported change_owner rule: {$rule}");
        }

        $pool = $this->pool($config);
        if ($pool === []) {
            return ActionPreview::wont('Candidate pool is empty.');
        }

        $cursor = (int) ($automation->round_robin_cursor ?? 0);
        $next = $pool[$cursor % count($pool)];

        return ActionPreview::will("Would reassign owner to user {$next}", [
            'change_owner' => [
                'rule' => 'round_robin',
                'current_owner' => $target->owner_user_id,
                'next_owner' => $next,
                'pool_size' => count($pool),
            ],
        ]);
    }

    /**
     * Resolve the candidate pool, ordered by id for a stable rotation.
     *
     * An explicit `pool` of user ids (the builder UI shape) wins; otherwise a
     * `user_pool_filter` of role/department applies; with neither, every active
     * user is a candidate. In all cases inactive users are excluded — a
     * hand-picked id of a since-deactivated user silently drops out so we never
     * round-robin onto a disabled account.
     *
     * @param  array<string, mixed>  $config
     * @return list<int>
     */
    private function pool(array $config): array
    {
        $query = User::query()->where('is_active', true);

        $ids = $this->explicitPoolIds($config['pool'] ?? null);

        if ($ids !== []) {
            // Hand-picked set: keep only the ones that are still active, ordered
            // by id so the cursor walks a deterministic sequence.
            $query->whereIn('id', $ids);
        } else {
            $filter = is_array($config['user_pool_filter'] ?? null) ? $config['user_pool_filter'] : [];

            if (! empty($filter['role'])) {
                $query->where('role', $filter['role']);
            }

            if (isset($filter['department'])) {
                $query->where('department_id', (int) $filter['department']);
            }
        }

        return $query->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * Coerce an arbitrary `pool` config value into a clean list of positive ints.
     *
     * @return list<int>
     */
    private function explicitPoolIds(mixed $pool): array
    {
        if (! is_array($pool)) {
            return [];
        }

        $ids = [];
        foreach ($pool as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true; // de-dup
            }
        }

        return array_keys($ids);
    }

    /**
     * Per-automation advisory lock (PG) for the cursor critical section. No-op on
     * other drivers (SQLite tests) where a single connection rules out the race.
     */
    private function acquireLock(int $automationId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // hashtext() yields a stable int4 key; per-automation so distinct
            // automations never block each other. Released at transaction end.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('rr:automation:'||?))", [(string) $automationId]);
        }
    }
}
