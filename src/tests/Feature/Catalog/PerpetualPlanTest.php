<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\BillingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * N4 — Perpetual licensing plan: BillingUnit::Perpetual, getPerpetualPlan,
 * one-per-product guard, getPriceSnapshot, regression for non-perpetual plans.
 */
class PerpetualPlanTest extends TestCase
{
    use RefreshDatabase;

    // ---- BillingUnit enum ----

    public function test_billing_unit_perpetual_case_exists_with_correct_value(): void
    {
        $unit = BillingUnit::Perpetual;

        $this->assertSame('perpetual', $unit->value);
        $this->assertSame('Вечная лицензия', $unit->label());
    }

    public function test_billing_unit_try_from_perpetual(): void
    {
        $unit = BillingUnit::tryFrom('perpetual');

        $this->assertSame(BillingUnit::Perpetual, $unit);
    }

    public function test_billing_unit_existing_cases_still_have_labels(): void
    {
        $this->assertSame('Год', BillingUnit::Year->label());
        $this->assertSame('Единоразово', BillingUnit::OneTime->label());
        $this->assertSame('Минута', BillingUnit::Minute->label());
        $this->assertSame('Пакет', BillingUnit::Package->label());
    }

    // ---- getPerpetualPlan ----

    public function test_get_perpetual_plan_returns_null_when_none_exists(): void
    {
        $product = Product::factory()->create();
        // Year plan — not perpetual.
        ProductPlan::factory()->create(['product_id' => $product->id, 'unit' => BillingUnit::Year]);

        $service = app(ProductService::class);

        $this->assertNull($service->getPerpetualPlan($product));
    }

    public function test_get_perpetual_plan_returns_plan_when_exists(): void
    {
        $product = Product::factory()->create();
        $perpetual = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
            'name' => 'Вечная лицензия',
        ]);

        $service = app(ProductService::class);
        $found = $service->getPerpetualPlan($product);

        $this->assertNotNull($found);
        $this->assertSame($perpetual->id, $found->id);
        $this->assertSame(BillingUnit::Perpetual, $found->unit);
    }

    public function test_get_perpetual_plan_accepts_integer_product_id(): void
    {
        $product = Product::factory()->create();
        $perpetual = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
        ]);

        $service = app(ProductService::class);
        $found = $service->getPerpetualPlan($product->id);

        $this->assertNotNull($found);
        $this->assertSame($perpetual->id, $found->id);
    }

    // ---- createPlan with perpetual + guard ----

    public function test_create_perpetual_plan_via_service(): void
    {
        $product = Product::factory()->create();
        $service = app(ProductService::class);

        $plan = $service->createPlan($product, [
            'name' => 'Вечная лицензия',
            'unit' => 'perpetual',
            'is_active' => true,
        ]);

        $this->assertSame(BillingUnit::Perpetual, $plan->unit);
        $this->assertDatabaseHas('catalog_product_plans', [
            'product_id' => $product->id,
            'unit' => 'perpetual',
        ]);
    }

    public function test_second_perpetual_plan_throws_422(): void
    {
        $this->expectException(HttpException::class);

        $product = Product::factory()->create();
        $service = app(ProductService::class);

        // First perpetual plan — OK.
        $service->createPlan($product, ['name' => 'Вечная лицензия', 'unit' => 'perpetual']);

        // Second perpetual plan — must abort 422.
        $service->createPlan($product, ['name' => 'Вечная лицензия 2', 'unit' => 'perpetual']);
    }

    public function test_second_perpetual_plan_via_api_returns_422(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // First perpetual plan — 201.
        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'name' => 'Вечная лицензия',
            'unit' => 'perpetual',
        ])->assertCreated();

        // Second perpetual plan — 422.
        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'name' => 'Вечная лицензия Дубль',
            'unit' => 'perpetual',
        ])->assertStatus(422);
    }

    public function test_guard_does_not_block_multiple_non_perpetual_plans(): void
    {
        $product = Product::factory()->create();
        $service = app(ProductService::class);

        // Two year-plans on the same product — both must succeed.
        $plan1 = $service->createPlan($product, ['name' => 'Старт', 'unit' => 'year']);
        $plan2 = $service->createPlan($product, ['name' => 'Бизнес', 'unit' => 'year']);

        $this->assertNotNull($plan1->id);
        $this->assertNotNull($plan2->id);
        $this->assertDatabaseCount('catalog_product_plans', 2);
    }

    // ---- getPriceSnapshot works for perpetual plan (no signature change) ----

    public function test_get_price_snapshot_returns_perpetual_price(): void
    {
        $product = Product::factory()->create();
        $plan = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'currency_code' => 'KZT',
            'amount' => 1_000_000_00,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'currency_code' => 'RUB',
            'amount' => 200_000_00,
        ]);

        $service = app(ProductService::class);

        $this->assertSame(1_000_000_00, $service->getPriceSnapshot($product->id, $plan->id, 'KZT'));
        $this->assertSame(200_000_00, $service->getPriceSnapshot($product->id, $plan->id, 'RUB'));
        $this->assertNull($service->getPriceSnapshot($product->id, $plan->id, 'USD'));
    }

    public function test_perpetual_plan_multicurrency_prices_all_accessible(): void
    {
        $currencies = ['KZT', 'RUB', 'USD'];
        $amounts = [1_000_000_00, 200_000_00, 3_000_00];

        $product = Product::factory()->create();
        $plan = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
            'name' => 'Вечная лицензия',
        ]);

        foreach (array_combine($currencies, $amounts) as $currency => $amount) {
            ProductPrice::factory()->create([
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'currency_code' => $currency,
                'amount' => $amount,
            ]);
        }

        $service = app(ProductService::class);

        foreach (array_combine($currencies, $amounts) as $currency => $amount) {
            $this->assertSame(
                $amount,
                $service->getPriceSnapshot($product->id, $plan->id, $currency),
                "getPriceSnapshot mismatch for {$currency}",
            );
        }
    }

    // ---- API: perpetual plan visible in ProductResource ----

    public function test_perpetual_plan_visible_in_product_resource(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $product = Product::factory()->create();
        ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
            'name' => 'Вечная лицензия',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/catalog/products/{$product->id}")
            ->assertOk();

        $plans = $response->json('data.plans');
        $this->assertNotNull($plans);
        $perpetualPlan = collect($plans)->firstWhere('unit', 'perpetual');
        $this->assertNotNull($perpetualPlan, 'Perpetual plan must appear in product resource');
        $this->assertSame('Вечная лицензия', $perpetualPlan['name']);
    }

    // ---- Regression: existing non-perpetual plans unaffected ----

    public function test_year_plan_creation_unaffected_by_perpetual_logic(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'name' => 'Годовой',
            'unit' => 'year',
        ])->assertCreated()
            ->assertJsonPath('data.unit', 'year');
    }

    public function test_one_time_plan_creation_unaffected(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'name' => 'Разовый',
            'unit' => 'one_time',
        ])->assertCreated()
            ->assertJsonPath('data.unit', 'one_time');
    }

    public function test_invalid_unit_still_rejected_by_validation(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $product = Product::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/catalog/products/{$product->id}/plans", [
            'name' => 'Bad',
            'unit' => 'nonexistent_unit',
        ])->assertStatus(422);
    }
}
