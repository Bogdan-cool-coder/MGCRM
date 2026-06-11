<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Http\Middleware\ResolveVisibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ResolveVisibilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_stamps_all_scope_for_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->assignRole(Role::Admin->value);

        $scope = $this->runMiddleware($admin);

        $this->assertSame(VisibilityScope::All, $scope);
    }

    public function test_stamps_own_scope_for_manager(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $manager->assignRole(Role::Manager->value);

        $scope = $this->runMiddleware($manager);

        $this->assertSame(VisibilityScope::Own, $scope);
    }

    public function test_fails_closed_to_own_for_guest(): void
    {
        $scope = $this->runMiddleware(null);

        $this->assertSame(VisibilityScope::Own, $scope);
    }

    private function runMiddleware(?User $user): VisibilityScope
    {
        $middleware = app(ResolveVisibility::class);
        $request = Request::create('/api/me', 'GET');
        $request->setUserResolver(fn () => $user);

        $captured = null;
        $middleware->handle($request, function (Request $req) use (&$captured) {
            $captured = $req->attributes->get(ResolveVisibility::ATTRIBUTE);

            return response('ok');
        });

        return $captured;
    }
}
