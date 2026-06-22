<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesManagersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagersSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const EXPECTED_EMAILS = [
        'ilyarogov.mera@gmail.com',
        'o.moiseeva@macroglobaltech.com',
        's.shomina@macroglobaltech.com',
        'g.nekrasov@macroglobaltech.com',
        'k.fedorin@macroglobaltech.com',
    ];

    private function seedManagers(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(SalesManagersSeeder::class);
    }

    public function test_seeder_creates_the_five_managers_with_role_and_department(): void
    {
        $this->seedManagers();

        $salesDept = Department::where('name', 'Отдел продаж')->firstOrFail();

        foreach (self::EXPECTED_EMAILS as $email) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, "Manager {$email} was not created");
            $this->assertSame(Role::Manager, $user->role);
            $this->assertTrue($user->hasRole(Role::Manager->value), "Manager {$email} is missing the spatie manager role");
            $this->assertSame($salesDept->id, $user->department_id, "Manager {$email} is not in Отдел продаж");
            $this->assertTrue($user->is_active);
            $this->assertFalse($user->is_service);
            $this->assertFalse($user->totp_enabled);
            $this->assertSame('Менеджер по продажам', $user->job_title);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentSeeder::class);

        $this->seed(SalesManagersSeeder::class);
        $countAfterFirst = User::whereIn('email', self::EXPECTED_EMAILS)->count();

        $this->seed(SalesManagersSeeder::class);
        $countAfterSecond = User::whereIn('email', self::EXPECTED_EMAILS)->count();

        $this->assertSame(5, $countAfterFirst);
        $this->assertSame(5, $countAfterSecond, 'Re-running the seeder must not create duplicate managers');
    }

    public function test_seeder_does_not_reset_password_on_rerun(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentSeeder::class);

        $this->seed(SalesManagersSeeder::class);
        $originalHash = User::where('email', self::EXPECTED_EMAILS[0])->value('password');

        $this->seed(SalesManagersSeeder::class);
        $afterHash = User::where('email', self::EXPECTED_EMAILS[0])->value('password');

        $this->assertSame($originalHash, $afterHash, 'Password hash must be preserved across re-runs');
    }

    public function test_manager_emails_match_amo_user_map_keys(): void
    {
        /** @var array<int, string> $userMap */
        $userMap = config('amo_migration.user_map');
        $mappedEmails = array_values($userMap);

        $intersection = array_intersect(self::EXPECTED_EMAILS, $mappedEmails);

        // Every seeded manager email must be present in the AMO user_map so the
        // migration ETL resolves their deals to them (not the fallback account).
        $this->assertSame(
            self::EXPECTED_EMAILS,
            array_values($intersection),
            'All seeded manager emails must be keys (values) in config amo_migration.user_map',
        );
    }

    public function test_seeder_self_provisions_department_when_missing(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // No DepartmentSeeder — the seeder must firstOrCreate Отдел продаж itself.
        $this->seed(SalesManagersSeeder::class);

        $this->assertDatabaseHas('departments', ['name' => 'Отдел продаж']);
        $salesDept = Department::where('name', 'Отдел продаж')->firstOrFail();

        $user = User::where('email', self::EXPECTED_EMAILS[0])->firstOrFail();
        $this->assertSame($salesDept->id, $user->department_id);
    }
}
