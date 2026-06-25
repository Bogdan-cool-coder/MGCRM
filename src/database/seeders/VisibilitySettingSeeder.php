<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Services\VisibilityConfigService;
use Illuminate\Database\Seeder;

/**
 * Seed the role × visibility-scope matrix with the CURRENT behavior — admin/
 * director/lawyer = all, manager/accountant/cfo = own — so VisibilityResolver
 * is unchanged until an admin edits the matrix (e2e regression locks stay green).
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
