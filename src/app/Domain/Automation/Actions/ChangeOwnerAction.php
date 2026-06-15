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
 * config: { rule?: "round_robin", user_pool_filter?: { role?, department? } }
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

        $pool = $this->pool($config['user_pool_filter'] ?? []);
        if ($pool === []) {
            return ActionResult::skipped('Candidate pool is empty.');
        }

        return DB::transaction(function () use ($automation, $target, $pool): ActionResult {
            $this->acquireLock($automation->id);

            // Re-read the cursor under the lock so concurrent ticks serialise.
            $locked = PipelineAutomation::query()->lockForUpdate()->find($automation->id);
            $cursor = (int) ($locked->round_robin_cursor ?? 0);

            $picked = $pool[$cursor % count($pool)];
            $nextCursor = ($cursor + 1) % count($pool);

            $locked->update(['round_robin_cursor' => $nextCursor]);

            $old = $target->owner_user_id;
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

        $pool = $this->pool($config['user_pool_filter'] ?? []);
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
     * Active users matching the filter, ordered by id (stable rotation).
     *
     * @param  array<string, mixed>  $filter
     * @return list<int>
     */
    private function pool(array $filter): array
    {
        $query = User::query()->where('is_active', true);

        if (! empty($filter['role'])) {
            $query->where('role', $filter['role']);
        }

        if (isset($filter['department'])) {
            $query->where('department_id', (int) $filter['department']);
        }

        return $query->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
