<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/registry (R1, contract §6.4):
 * expected-in-month list, «дожим» squeeze list, no-date counter, visibility scope.
 */
class ExpectedIncomeRegistryReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true]);
    }

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    public function test_expected_list_contains_open_deal_with_expected_payment_date_in_month(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();
        $company = Company::factory()->create(['name' => 'Qala Dev']);

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'company_id' => $company->id,
            'title' => 'Qala Dev — CRM',
            'amount' => 985_750_00,
            'currency' => 'RUB',
            'expected_payment_date' => '2026-01-20',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();

        $rows = collect($response->json('expected.rows'));
        $row = $rows->firstWhere('deal_id', $deal->id);

        $this->assertNotNull($row, 'expected deal missing from registry');
        $this->assertSame('Qala Dev', $row['company_name']);
        $this->assertSame(985_750_00, $row['amount_kopecks']);
        $this->assertSame('2026-01-20', $row['expected_payment_date']);
        $this->assertSame(985_750_00, $response->json('expected.total_base_kopecks'));
    }

    public function test_squeeze_list_contains_overdue_unpaid_open_deal(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 500_000_00,
            'currency' => 'RUB',
            'expected_payment_date' => now()->subDays(10)->toDateString(),
            'paid_at' => null,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year='.now()->year.'&month='.now()->month)->assertOk();

        $rows = collect($response->json('squeeze.rows'));
        $this->assertNotNull($rows->firstWhere('deal_id', $deal->id), 'overdue unpaid deal missing from squeeze');
        $this->assertSame(500_000_00, $response->json('squeeze.total_base_kopecks'));
    }

    public function test_paid_overdue_deal_is_excluded_from_squeeze(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00,
            'currency' => 'RUB',
            'expected_payment_date' => now()->subDays(5)->toDateString(),
            'paid_at' => now()->subDay(),
            'paid_amount' => 100_000_00,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year='.now()->year.'&month='.now()->month)->assertOk();

        $rows = collect($response->json('squeeze.rows'));
        $this->assertNull($rows->firstWhere('deal_id', $deal->id), 'paid overdue deal must not appear in squeeze');
    }

    public function test_no_date_count_reflects_open_deals_without_expected_payment_date(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();

        Deal::factory()->forOwner($manager)->inStage($stage)->count(3)->create([
            'expected_payment_date' => null,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();

        $this->assertSame(3, $response->json('squeeze.no_date_count'));
    }

    /**
     * BUG-REGISTRY-NODATE-LIST-EMPTY regression: `no_date_count` must not be
     * counted independently of the rows the FE actually needs to render — the
     * open deal itself must appear in `squeeze.no_date_rows`, in the same row
     * shape as expected/squeeze.rows, and the count must equal that list's size.
     */
    public function test_no_date_rows_contains_the_open_deal_without_expected_payment_date(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();
        $company = Company::factory()->create(['name' => 'Acme LLC']);

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'company_id' => $company->id,
            'title' => 'Acme — support renewal',
            'amount' => 250_000_00,
            'currency' => 'RUB',
            'expected_payment_date' => null,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();

        $rows = collect($response->json('squeeze.no_date_rows'));
        $row = $rows->firstWhere('deal_id', $deal->id);

        $this->assertNotNull($row, 'deal without expected_payment_date missing from no_date_rows');
        $this->assertSame('Acme LLC', $row['company_name']);
        $this->assertSame(250_000_00, $row['amount_kopecks']);
        $this->assertNull($row['expected_payment_date']);
        $this->assertSame($rows->count(), $response->json('squeeze.no_date_count'), 'no_date_count must match the no_date_rows list size');
    }

    public function test_manager_does_not_see_unrelated_manager_deal(): void
    {
        $manager = $this->makeManager();
        $otherManager = $this->makeManager();
        $stage = $this->openStage();

        $foreignDeal = Deal::factory()->forOwner($otherManager)->inStage($stage)->create([
            'expected_payment_date' => '2026-01-20',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();

        $rows = collect($response->json('expected.rows'));
        $this->assertNull($rows->firstWhere('deal_id', $foreignDeal->id), 'manager leaked another manager\'s deal into registry');
    }

    public function test_director_sees_all_managers_deals(): void
    {
        $director = $this->makeDirector();
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $stage = $this->openStage();

        $dealA = Deal::factory()->forOwner($managerA)->inStage($stage)->create(['expected_payment_date' => '2026-01-05']);
        $dealB = Deal::factory()->forOwner($managerB)->inStage($stage)->create(['expected_payment_date' => '2026-01-15']);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();

        $rows = collect($response->json('expected.rows'));
        $this->assertNotNull($rows->firstWhere('deal_id', $dealA->id));
        $this->assertNotNull($rows->firstWhere('deal_id', $dealB->id));
    }

    public function test_last_task_is_surfaced_on_the_row(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'expected_payment_date' => '2026-01-20',
        ]);

        Activity::factory()->create([
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'result_text' => 'Ждём оплату до 20/01',
            'responsible_id' => $manager->id,
            'created_by_id' => $manager->id,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();
        $row = collect($response->json('expected.rows'))->firstWhere('deal_id', $deal->id);

        $this->assertSame('Ждём оплату до 20/01', $row['last_task']['result_text']);
    }

    public function test_products_line_items_are_surfaced(): void
    {
        $manager = $this->makeManager();
        $stage = $this->openStage();

        $deal = Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'expected_payment_date' => '2026-01-20',
        ]);

        $product = Product::factory()->create(['name' => 'MacroSales CRM']);
        DealProduct::factory()->create(['deal_id' => $deal->id, 'product_id' => $product->id]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/reports/registry?year=2026&month=1')->assertOk();
        $row = collect($response->json('expected.rows'))->firstWhere('deal_id', $deal->id);

        $this->assertSame('MacroSales CRM', $row['products'][0]['name']);
    }
}
