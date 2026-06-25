<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Models\VisibilitySetting;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * VisibilityConfigService — the authoritative, cached reader/writer of the role ×
 * visibility-scope matrix (visibility_settings table). VisibilityResolver calls
 * map() to resolve a role's scope at runtime instead of the hardcoded
 * VisibilityScope::forRole() default map.
 *
 * Reads are cached under a single key (the whole matrix is tiny — 6 rows) and
 * busted on every write. Defaults mirror the legacy behavior (admin/director/
 * lawyer = all, manager/accountant/cfo = own) so authz is unchanged until an
 * admin edits the matrix; a role with no row falls back to that default
 * (fail-closed to Own for unknown roles via VisibilityScope::forRole).
 *
 * Every update is appended to entity_logs (System subject, VisibilityChanged
 * action) for the audit trail Settings → Access Control requires.
 */
class VisibilityConfigService
{
    private const CACHE_KEY = 'iam.visibility_settings';

    public function __construct(
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * The full role => scope map, cached. Roles missing a stored row fall back to
     * the legacy default (VisibilityScope::forRole) so the matrix is always
     * complete for the six fixed roles.
     *
     * @return array<string, VisibilityScope>
     */
    public function map(): array
    {
        /** @var array<string, string> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, static function (): array {
            // Read raw string values (not the model's enum cast) so the cache
            // payload is a plain string map that survives serialization.
            return VisibilitySetting::query()
                ->pluck('scope', 'role')
                ->map(static fn ($scope): string => $scope instanceof VisibilityScope ? $scope->value : (string) $scope)
                ->all();
        });

        $map = [];
        foreach (Role::values() as $role) {
            $map[$role] = isset($stored[$role])
                ? (VisibilityScope::tryFrom($stored[$role]) ?? VisibilityScope::forRole($role))
                : VisibilityScope::forRole($role);
        }

        return $map;
    }

    /**
     * Resolve the configured scope for a single role name. Unknown / null roles
     * fail closed to Own (via VisibilityScope::forRole).
     */
    public function scopeForRole(?string $role): VisibilityScope
    {
        if ($role === null) {
            return VisibilityScope::Own;
        }

        return $this->map()[$role] ?? VisibilityScope::forRole($role);
    }

    /**
     * Upsert one or more role => scope assignments, audit the change and bust the
     * cache. Only the six fixed roles are accepted; unknown roles are ignored.
     * Returns the fresh, complete matrix.
     *
     * @param  array<string, VisibilityScope|string>  $changes  role => scope
     * @return array<string, VisibilityScope>
     */
    public function update(array $changes, ?User $actor = null): array
    {
        $before = $this->map();
        $applied = [];

        DB::transaction(function () use ($changes, &$applied): void {
            foreach ($changes as $role => $scope) {
                if (! in_array($role, Role::values(), true)) {
                    continue;
                }

                $scopeValue = $scope instanceof VisibilityScope ? $scope->value : (string) $scope;
                $scopeEnum = VisibilityScope::tryFrom($scopeValue);

                if ($scopeEnum === null) {
                    continue;
                }

                VisibilitySetting::query()->updateOrCreate(
                    ['role' => $role],
                    ['scope' => $scopeEnum->value],
                );

                $applied[$role] = $scopeEnum;
            }
        });

        $this->flush();
        $after = $this->map();

        // Audit only the roles whose scope actually changed.
        foreach ($applied as $role => $scopeEnum) {
            $from = $before[$role] ?? null;
            if ($from === $scopeEnum) {
                continue;
            }

            $this->entityLog->record(
                LogSubjectType::System,
                $actor?->id ?? 0,
                $actor,
                LogAction::VisibilityChanged,
                [
                    'role' => $role,
                    'from' => $from?->value,
                    'to' => $scopeEnum->value,
                ],
            );
        }

        return $after;
    }

    /**
     * Seed the default matrix (idempotent) — current behavior so existing tests
     * and e2e regression locks stay green. Called by VisibilitySettingSeeder and
     * lazily by map() callers via ensureSeeded().
     */
    public function seedDefaults(): void
    {
        foreach (Role::values() as $role) {
            VisibilitySetting::query()->updateOrCreate(
                ['role' => $role],
                ['scope' => VisibilityScope::forRole($role)->value],
            );
        }

        $this->flush();
    }

    /**
     * Bust the cached matrix.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
