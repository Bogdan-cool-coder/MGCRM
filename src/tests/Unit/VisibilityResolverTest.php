<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private VisibilityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->resolver = app(VisibilityResolver::class);
    }

    public function test_admin_resolves_to_all_scope(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->assignRole(Role::Admin->value);

        $this->assertSame(VisibilityScope::All, $this->resolver->resolve($admin));
    }

    public function test_manager_resolves_to_department_scope(): void
    {
        // M9: manager default is Department (team read scope), not Own.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $manager->assignRole(Role::Manager->value);

        $this->assertSame(VisibilityScope::Department, $this->resolver->resolve($manager));
    }

    public function test_accountant_resolves_to_own_scope(): void
    {
        // Accountant/cfo stay Own — the M9 department read is manager-only.
        $accountant = User::factory()->create(['role' => Role::Accountant]);
        $accountant->assignRole(Role::Accountant->value);

        $this->assertSame(VisibilityScope::Own, $this->resolver->resolve($accountant));
    }

    public function test_user_without_spatie_role_falls_back_to_mirror_column(): void
    {
        // No spatie role assigned; resolver falls back to the role enum column.
        $director = User::factory()->create(['role' => Role::Director]);

        $this->assertSame(VisibilityScope::All, $this->resolver->resolve($director));
    }
}
