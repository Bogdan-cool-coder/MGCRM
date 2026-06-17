<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Org\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' department',
            'parent_id' => null,
            'manager_id' => null,
        ];
    }
}
