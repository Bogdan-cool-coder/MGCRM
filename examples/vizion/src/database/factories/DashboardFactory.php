<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    protected $model = Dashboard::class;

    public function definition(): array
    {
        return [
            'name'         => ['ru' => 'Дашборд', 'en' => 'Dashboard'],
            'is_system'    => false,
            'is_published' => false,
            // company_id / user_id are supplied by the caller (no orphan FK).
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
            'user_id'   => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true]);
    }
}
