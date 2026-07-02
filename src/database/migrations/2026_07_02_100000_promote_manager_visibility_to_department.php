<?php

declare(strict_types=1);

use App\Domain\Iam\Services\VisibilityConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M9 activation-on-deploy: promote the persisted visibility scope for the `manager`
 * role from `own` → `department` (full department CRUD).
 *
 * The code default (VisibilityScope::forRole) already returns Department for a
 * manager, and fresh installs seed that. But existing installs were seeded before
 * M9 with a stored `manager=own` row in visibility_settings, and the stored value
 * WINS over the code default (VisibilityConfigService reads the DB row). So the code
 * change alone does nothing on those installs — this migration flips the stored row.
 *
 * SAFE / IDEMPOTENT / NON-CLOBBERING:
 *   - Only touches the `manager` row, and only when it is STILL `own`. If an admin
 *     has deliberately customized manager to some other scope, it is left untouched.
 *   - If the row is already `department` (fresh install, or a re-run) it matches
 *     nothing → no-op. Re-running the migration is harmless.
 *   - No-op when there is no `manager` row at all (an unseeded install falls back to
 *     the code default, which is already Department).
 *
 * REVERSIBLE: down() restores manager `department` → `own` (only when it is still
 * `department`, mirroring the up guard).
 *
 * The cached matrix (VisibilityConfigService) is flushed both ways so the change
 * takes effect on the first request after deploy without a manual cache clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visibility_settings')
            ->where('role', 'manager')
            ->where('scope', 'own')
            ->update([
                'scope' => 'department',
                'updated_at' => now(),
            ]);

        $this->flushVisibilityCache();
    }

    public function down(): void
    {
        DB::table('visibility_settings')
            ->where('role', 'manager')
            ->where('scope', 'department')
            ->update([
                'scope' => 'own',
                'updated_at' => now(),
            ]);

        $this->flushVisibilityCache();
    }

    /**
     * Bust the cached role × scope matrix so the new value is read on the next
     * request. Guarded: the service resolves the cache store, which is always
     * available under migrate --force.
     */
    private function flushVisibilityCache(): void
    {
        app(VisibilityConfigService::class)->flush();
    }
};
