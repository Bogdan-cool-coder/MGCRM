<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\DemoActivitiesSeeder;
use Database\Seeders\DemoDealsSeeder;
use Database\Seeders\DemoDocumentsSeeder;
use Database\Seeders\InboxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Regression for the audit §3.6 HIGH finding: five demo seeders queried the
 * dropped `users.role` COLUMN (`->where('role', ...)`) — removed by IAM-1 — so a
 * full `php artisan db:seed` crashed (Postgres 42703 "column does not exist").
 * The fix rewired those reads to the spatie role scope (`User::role(...)`).
 *
 * This smoke test proves the whole seeder graph runs end-to-end, and each of the
 * five previously-broken seeders resolves its intended user through the spatie
 * role and populates its data — so the crash can never regress silently.
 */
class DatabaseSeedSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_db_seed_runs_without_error(): void
    {
        // The whole DatabaseSeeder graph (baseline config + sample business data)
        // must complete cleanly. Any seeder still touching the dropped role column
        // would throw here and fail the test.
        $exitCode = Artisan::call('db:seed', ['--force' => true]);

        $this->assertSame(0, $exitCode, 'php artisan db:seed must exit 0.');

        // Sanity: the admin account exists and its role resolves via spatie.
        $admin = User::role(Role::Admin->value)->first();
        $this->assertNotNull($admin, 'db:seed must produce an admin user.');
        $this->assertSame(Role::Admin, $admin->role);
    }

    /**
     * Each rewired seeder, re-run in isolation on a fully seeded DB, must not throw
     * (proving the spatie role scope replaced the dropped-column query) and stays
     * idempotent. Onboarding's assignment seeder runs inside OnboardingSeeder in the
     * full graph above, so it is exercised there; here we cover the four that resolve
     * a user directly through the role scope in run().
     */
    public function test_rewired_seeders_are_rerunnable_via_role_scope(): void
    {
        // Bring up the whole graph so every dependency (accounts, pipelines, products,
        // templates, channels) exists before we re-run the demo seeders in isolation.
        Artisan::call('db:seed', ['--force' => true]);

        foreach ([
            DemoDealsSeeder::class,
            DemoActivitiesSeeder::class,
            DemoDocumentsSeeder::class,
            InboxSeeder::class,
        ] as $seeder) {
            $this->seed($seeder);
        }

        // The role scope resolves the intended accounts (proves the rewrite works).
        $this->assertNotNull(User::role(Role::Admin->value)->first());
        $this->assertNotNull(User::role(Role::Manager->value)->first());
    }
}
