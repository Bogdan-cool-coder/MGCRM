<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Services\VisibilityConfigService;
use Illuminate\Database\Seeder;

/**
 * Seed the role × visibility-scope matrix with the current defaults — admin/
 * director/lawyer = all, manager = department (M9: full department CRUD),
 * accountant/cfo = own. seedDefaults() reads VisibilityScope::forRole(), so this
 * tracks the code default automatically; a fresh install gets manager=department.
 *
 * Existing prod installs seeded BEFORE M9 keep their stored manager=own row; the
 * 2026_07_02 migration promotes that own → department in place (only when it is
 * still own, so an admin-customized value is preserved).
 *
 * Idempotent (updateOrCreate per role). Baseline seeder: re-run by the clean
 * reset.
 */
class VisibilitySettingSeeder extends Seeder
{
    public function run(): void
    {
        app(VisibilityConfigService::class)->seedDefaults();
    }
}
