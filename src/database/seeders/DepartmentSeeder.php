<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Org\Models\Department;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the default department directory, so the
 * "add user" form has a non-empty department Select even on a clean baseline.
 * Re-running does not create duplicates (firstOrCreate by name). The demo
 * seeders (SalesPulseDemoSeeder / ManagerKpiSeeder) also firstOrCreate their own
 * departments by name, so they coexist without conflict.
 */
class DepartmentSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const DEFAULT_DEPARTMENTS = [
        'Отдел продаж',
        'Юридический отдел',
        'Бухгалтерия',
        'Сопровождение клиентов',
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_DEPARTMENTS as $name) {
            Department::firstOrCreate(
                ['name' => $name],
                ['parent_id' => null, 'manager_id' => null],
            );
        }
    }
}
