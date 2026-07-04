<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Э2 finding #4 — SeedQaStudentCommand production guard.
 *
 * The command seeds a well-known account (qa-student@mgcrm.test / "password"). Its
 * docblock promised "dev only, never run on production" but nothing enforced it.
 * It now refuses to run under APP_ENV=production unless --force is passed
 * (mirrors Laravel's own destructive artisan convention).
 */
class SeedQaStudentCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_to_run_on_production_without_force(): void
    {
        Course::factory()->create(['id' => 1]);
        $this->app['env'] = 'production';

        $this->artisan('onboarding:seed-qa-student')
            ->assertFailed();

        // Nothing was seeded.
        $this->assertDatabaseMissing('users', ['email' => 'qa-student@mgcrm.test']);
    }

    public function test_force_flag_lets_it_run_on_production(): void
    {
        Course::factory()->create(['id' => 1]);
        $this->app['env'] = 'production';

        $this->artisan('onboarding:seed-qa-student --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'qa-student@mgcrm.test']);
    }

    public function test_runs_without_force_outside_production(): void
    {
        // The base TestCase runs under APP_ENV=testing — no guard trips.
        Course::factory()->create(['id' => 1]);

        $this->artisan('onboarding:seed-qa-student')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'qa-student@mgcrm.test']);
    }

    public function test_seeded_student_has_the_manager_role(): void
    {
        Course::factory()->create(['id' => 1]);

        $this->artisan('onboarding:seed-qa-student')->assertSuccessful();

        $student = User::where('email', 'qa-student@mgcrm.test')->firstOrFail();
        $this->assertTrue($student->hasRole('manager'));
    }
}
