<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Database\Seeders\AmoImportUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AMO fallback import service account (DEC-C). SAMPLE seeder, idempotent.
 */
class AmoImportUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'import-amo@mgcrm.local';

    public function test_seeder_creates_the_service_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoImportUserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertSame('Импорт АМО', $user->full_name);
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->is_service);
        $this->assertSame(Role::Manager, $user->role);
        $this->assertTrue($user->hasRole(Role::Manager->value));
    }

    public function test_seeder_sets_a_usable_random_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoImportUserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertNotNull($user->password);
        $this->assertNotSame('', $user->password);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AmoImportUserSeeder::class);

        $first = User::where('email', self::EMAIL)->firstOrFail();
        $originalHash = $first->password;

        // Re-run: no duplicate, password preserved.
        $this->seed(AmoImportUserSeeder::class);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());

        $reloaded = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame($originalHash, $reloaded->password);
        $this->assertTrue($reloaded->is_service);
        $this->assertTrue($reloaded->hasRole(Role::Manager->value));
    }
}
