<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for POST /api/plans/copy-previous (P-3, contract §6.3).
 */
class PlanCopyPreviousTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
    }

    public function test_manager_403_on_copy_previous(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/plans/copy-previous', [
            'metric' => 'new_income', 'layer' => 'operative', 'scope_type' => 'user',
            'from_year' => 2025, 'from_month' => 12, 'to_year' => 2026, 'to_month' => 1,
        ])->assertForbidden();
    }

    public function test_copies_single_month(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2025, 12)->create([
            'value_kopecks' => 4_000_000_00,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/plans/copy-previous', [
            'metric' => 'new_income', 'layer' => 'operative', 'scope_type' => 'user',
            'from_year' => 2025, 'from_month' => 12, 'to_year' => 2026, 'to_month' => 1,
        ])->assertOk();

        $response->assertJsonPath('data.created', 1);

        $copied = PlanTarget::query()->where('period_year', 2026)->where('period_month', 1)->first();
        $this->assertNotNull($copied);
        $this->assertSame(4_000_000_00, $copied->value_kopecks);
    }

    public function test_copies_whole_year_when_months_omitted(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();

        foreach (range(1, 12) as $month) {
            PlanTarget::factory()->forUser($manager->id)->forPeriod(2025, $month)->create([
                'value_kopecks' => 1_000_000_00 * $month,
                'currency' => 'RUB',
            ]);
        }

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/plans/copy-previous', [
            'metric' => 'new_income', 'layer' => 'operative', 'scope_type' => 'user',
            'from_year' => 2025, 'to_year' => 2026,
        ])->assertOk();

        $response->assertJsonPath('data.created', 12);
        $this->assertSame(12, PlanTarget::query()->where('period_year', 2026)->count());
    }

    public function test_skips_cells_that_already_exist_in_target(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2025, 12)->create([
            'value_kopecks' => 4_000_000_00,
            'currency' => 'RUB',
        ]);
        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 1)->create([
            'value_kopecks' => 9_999_000_00, // pre-existing target — must NOT be overwritten
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/plans/copy-previous', [
            'metric' => 'new_income', 'layer' => 'operative', 'scope_type' => 'user',
            'from_year' => 2025, 'from_month' => 12, 'to_year' => 2026, 'to_month' => 1,
        ])->assertOk();

        $response->assertJsonPath('data.created', 0);

        $target = PlanTarget::query()->where('period_year', 2026)->where('period_month', 1)->first();
        $this->assertSame(9_999_000_00, $target->value_kopecks, 'copy-previous must never overwrite an existing target cell');
    }
}
