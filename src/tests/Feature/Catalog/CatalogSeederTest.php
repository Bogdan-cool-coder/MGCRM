<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use Database\Seeders\ProductGroupSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the MACRO Global 2026 official price-list catalog seeder.
 *
 * Expectations (derived from the 2026 price list):
 *   - 8 product groups
 *   - 32 products (30 priced + ППИ + AEO/GEO as custom/no-price)
 *   - 4 plans for Voice AI Broker, 5 plans for Macro3D Tours
 *   - Prices stored in minor units (kopecks / tiyn / fils / cents)
 *   - Seeder is fully idempotent (re-run does NOT duplicate rows)
 */
class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeders(): void
    {
        $this->seed(ProductGroupSeeder::class);
        $this->seed(ProductSeeder::class);
    }

    // ── Groups ─────────────────────────────────────────────────────────────────

    public function test_seeds_8_product_groups(): void
    {
        $this->runSeeders();

        $this->assertSame(8, ProductGroup::count());
    }

    public function test_group_names_match_price_list(): void
    {
        $this->runSeeders();

        $expectedGroups = [
            'MacroSales CRM',
            'MACRO AI',
            'MacroCatalog',
            'MacroBroker',
            'Macro3D Tours',
            'MacroERP',
            'FlowFix',
            'MACRO Services',
        ];

        foreach ($expectedGroups as $name) {
            $this->assertDatabaseHas('catalog_product_groups', ['name' => $name]);
        }
    }

    public function test_groups_are_active_and_have_correct_sort_order(): void
    {
        $this->runSeeders();

        $this->assertDatabaseHas('catalog_product_groups', ['name' => 'MacroSales CRM', 'sort_order' => 1, 'is_active' => true]);
        $this->assertDatabaseHas('catalog_product_groups', ['name' => 'MACRO AI', 'sort_order' => 2, 'is_active' => true]);
        $this->assertDatabaseHas('catalog_product_groups', ['name' => 'MACRO Services', 'sort_order' => 8, 'is_active' => true]);
    }

    // ── Products ───────────────────────────────────────────────────────────────

    public function test_seeds_32_products(): void
    {
        $this->runSeeders();

        $this->assertSame(32, Product::count());
    }

    public function test_key_product_codes_exist(): void
    {
        $this->runSeeders();

        $codes = [
            'macro_sales_crm',
            'setup_macro_sales_crm',
            'touchlink',
            'setup_touchlink',
            'ai_mailings',
            'setup_ai_mailings',
            'whatsapp_ai_broker',
            'setup_whatsapp_ai_broker',
            'telegram_ai_broker',
            'setup_telegram_ai_broker',
            'web_ai_broker',
            'setup_web_ai_broker',
            'facebook_ai_broker',
            'setup_facebook_ai_broker',
            'instagram_ai_broker',
            'setup_instagram_ai_broker',
            'voice_ai_broker',
            'setup_voice_ai_broker',
            'data_analytics_ai',
            'setup_data_analytics_ai',
            'macro_catalog',
            'macro_web',
            'customer_portal',
            'telegram_mini_app',
            'macro_broker',
            'macro3d_tours',
            'macro_erp',
            'setup_macro_erp',
            'flowfix',
            'setup_flowfix',
            'ppi',
            'aeo_geo_ai_optimizer',
        ];

        foreach ($codes as $code) {
            $this->assertDatabaseHas('catalog_products', ['code' => $code, 'is_active' => true]);
        }
    }

    public function test_products_are_assigned_to_correct_groups(): void
    {
        $this->runSeeders();

        $crmGroup = ProductGroup::where('name', 'MacroSales CRM')->firstOrFail();
        $aiGroup = ProductGroup::where('name', 'MACRO AI')->firstOrFail();
        $catalogGroup = ProductGroup::where('name', 'MacroCatalog')->firstOrFail();
        $erpGroup = ProductGroup::where('name', 'MacroERP')->firstOrFail();
        $servicesGroup = ProductGroup::where('name', 'MACRO Services')->firstOrFail();

        $this->assertDatabaseHas('catalog_products', ['code' => 'macro_sales_crm', 'group_id' => $crmGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'touchlink', 'group_id' => $crmGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'whatsapp_ai_broker', 'group_id' => $aiGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'voice_ai_broker', 'group_id' => $aiGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'macro_catalog', 'group_id' => $catalogGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'macro_erp', 'group_id' => $erpGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'ppi', 'group_id' => $servicesGroup->id]);
        $this->assertDatabaseHas('catalog_products', ['code' => 'aeo_geo_ai_optimizer', 'group_id' => $servicesGroup->id]);
    }

    // ── Pricing types ──────────────────────────────────────────────────────────

    public function test_setup_products_have_fixed_pricing_type(): void
    {
        $this->runSeeders();

        // All setup/* products are fixed pricing (one-time plan handles billing unit)
        $setupCodes = [
            'setup_macro_sales_crm',
            'setup_touchlink',
            'setup_ai_mailings',
            'setup_whatsapp_ai_broker',
            'setup_telegram_ai_broker',
            'setup_web_ai_broker',
            'setup_facebook_ai_broker',
            'setup_instagram_ai_broker',
            'setup_voice_ai_broker',
            'setup_data_analytics_ai',
            'setup_macro_erp',
            'setup_flowfix',
        ];

        foreach ($setupCodes as $code) {
            $this->assertDatabaseHas('catalog_products', ['code' => $code, 'pricing_type' => 'fixed']);
        }
    }

    public function test_custom_quote_products_have_custom_pricing_type(): void
    {
        $this->runSeeders();

        $this->assertDatabaseHas('catalog_products', ['code' => 'ppi', 'pricing_type' => 'custom']);
        $this->assertDatabaseHas('catalog_products', ['code' => 'aeo_geo_ai_optimizer', 'pricing_type' => 'custom']);
    }

    public function test_voice_ai_broker_is_per_minute(): void
    {
        $this->runSeeders();

        $this->assertDatabaseHas('catalog_products', ['code' => 'voice_ai_broker', 'pricing_type' => 'per_minute']);
    }

    public function test_macro3d_tours_is_package(): void
    {
        $this->runSeeders();

        $this->assertDatabaseHas('catalog_products', ['code' => 'macro3d_tours', 'pricing_type' => 'package']);
    }

    // ── Plans ──────────────────────────────────────────────────────────────────

    public function test_voice_ai_broker_has_4_plans(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'voice_ai_broker')->firstOrFail();
        $this->assertSame(4, $product->plans()->count());
    }

    public function test_voice_ai_broker_plan_codes_correct(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'voice_ai_broker')->firstOrFail();
        $planCodes = $product->plans()->pluck('code')->sort()->values()->toArray();

        $this->assertEqualsCanonicalizing(
            ['per_min', 'start_100', 'basic_250', 'pro_500'],
            $planCodes,
        );
    }

    public function test_voice_ai_broker_plan_units_are_correct(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'voice_ai_broker')->firstOrFail();

        $perMinPlan = $product->plans()->where('code', 'per_min')->firstOrFail();
        $this->assertSame('minute', $perMinPlan->unit->value);

        $start100Plan = $product->plans()->where('code', 'start_100')->firstOrFail();
        $this->assertSame('package', $start100Plan->unit->value);
    }

    public function test_macro3d_tours_has_5_plans(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro3d_tours')->firstOrFail();
        $this->assertSame(5, $product->plans()->count());
    }

    public function test_macro3d_tours_plan_codes_correct(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro3d_tours')->firstOrFail();
        $planCodes = $product->plans()->pluck('code')->sort()->values()->toArray();

        $this->assertEqualsCanonicalizing(
            ['pro_multi_set', 'multi_set', 'pro_set', 'plus_set', 'basic_set'],
            $planCodes,
        );
    }

    public function test_setup_products_each_have_one_setup_plan(): void
    {
        $this->runSeeders();

        $setupCodes = [
            'setup_macro_sales_crm',
            'setup_touchlink',
            'setup_whatsapp_ai_broker',
            'setup_macro_erp',
            'setup_flowfix',
        ];

        foreach ($setupCodes as $code) {
            $product = Product::where('code', $code)->firstOrFail();
            $this->assertSame(1, $product->plans()->count(), "Expected 1 plan for {$code}");

            $plan = $product->plans()->firstOrFail();
            $this->assertSame('setup', $plan->code);
            $this->assertSame('one_time', $plan->unit->value);
        }
    }

    // ── Prices & kopecks ──────────────────────────────────────────────────────

    public function test_macro_sales_crm_has_5_currency_prices(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro_sales_crm')->firstOrFail();
        $this->assertSame(5, $product->prices()->whereNull('plan_id')->count());
    }

    public function test_macro_sales_crm_prices_are_in_kopecks(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro_sales_crm')->firstOrFail();

        $prices = $product->prices()->whereNull('plan_id')->get()->keyBy('currency_code');

        // 2 500 000 KZT × 100 = 250_000_000
        $this->assertSame(250_000_000, $prices['KZT']->amount);
        // 50 000 000 UZS × 100 = 5_000_000_000
        $this->assertSame(5_000_000_000, $prices['UZS']->amount);
        // 60 000 AED × 100 = 6_000_000
        $this->assertSame(6_000_000, $prices['AED']->amount);
        // 4 500 USD × 100 = 450_000
        $this->assertSame(450_000, $prices['USD']->amount);
        // 360 000 RUB × 100 = 36_000_000
        $this->assertSame(36_000_000, $prices['RUB']->amount);
    }

    public function test_macro_erp_prices_are_correct_in_kopecks(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro_erp')->firstOrFail();
        $prices = $product->prices()->whereNull('plan_id')->get()->keyBy('currency_code');

        // 10 000 000 KZT × 100 = 1_000_000_000
        $this->assertSame(1_000_000_000, $prices['KZT']->amount);
        // 15 000 USD × 100 = 1_500_000
        $this->assertSame(1_500_000, $prices['USD']->amount);
        // 1 500 000 RUB × 100 = 150_000_000
        $this->assertSame(150_000_000, $prices['RUB']->amount);
    }

    public function test_setup_macro_sales_crm_plan_prices_correct(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'setup_macro_sales_crm')->firstOrFail();
        $plan = $product->plans()->where('code', 'setup')->firstOrFail();
        $prices = ProductPrice::where('plan_id', $plan->id)->get()->keyBy('currency_code');

        // 500 000 KZT × 100 = 50_000_000
        $this->assertSame(50_000_000, $prices['KZT']->amount);
        // 900 USD × 100 = 90_000
        $this->assertSame(90_000, $prices['USD']->amount);
        // 80 000 RUB × 100 = 8_000_000
        $this->assertSame(8_000_000, $prices['RUB']->amount);
    }

    public function test_voice_ai_broker_per_min_plan_prices_correct(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'voice_ai_broker')->firstOrFail();
        $plan = $product->plans()->where('code', 'per_min')->firstOrFail();
        $prices = ProductPrice::where('plan_id', $plan->id)->get()->keyBy('currency_code');

        // 100 KZT × 100 = 10_000
        $this->assertSame(10_000, $prices['KZT']->amount);
        // 2 300 UZS × 100 = 230_000
        $this->assertSame(230_000, $prices['UZS']->amount);
        // 15 RUB × 100 = 1_500
        $this->assertSame(1_500, $prices['RUB']->amount);
    }

    public function test_macro3d_tours_pro_multi_set_price_is_usd_only(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'macro3d_tours')->firstOrFail();
        $plan = $product->plans()->where('code', 'pro_multi_set')->firstOrFail();
        $prices = ProductPrice::where('plan_id', $plan->id)->get();

        $this->assertSame(1, $prices->count());
        $this->assertSame('USD', $prices->first()->currency_code);
        // $150 × 100 = 15_000
        $this->assertSame(15_000, $prices->first()->amount);
    }

    // ── Custom-quote products have no prices ──────────────────────────────────

    public function test_ppi_has_no_prices(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'ppi')->firstOrFail();
        $this->assertSame(0, ProductPrice::where('product_id', $product->id)->count());
    }

    public function test_aeo_geo_ai_optimizer_has_no_prices(): void
    {
        $this->runSeeders();

        $product = Product::where('code', 'aeo_geo_ai_optimizer')->firstOrFail();
        $this->assertSame(0, ProductPrice::where('product_id', $product->id)->count());
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_group_seeder_is_idempotent(): void
    {
        $this->seed(ProductGroupSeeder::class);
        $this->seed(ProductGroupSeeder::class);

        $this->assertSame(8, ProductGroup::count());
    }

    public function test_product_seeder_is_idempotent(): void
    {
        $this->runSeeders();
        $this->seed(ProductSeeder::class); // second run

        $this->assertSame(32, Product::count());

        // Voice AI Broker still has exactly 4 plans after re-run
        $product = Product::where('code', 'voice_ai_broker')->firstOrFail();
        $this->assertSame(4, $product->plans()->count());
    }

    public function test_prices_are_upserted_not_duplicated_on_rerun(): void
    {
        $this->runSeeders();
        $firstRunPriceCount = ProductPrice::count();

        $this->seed(ProductSeeder::class);
        $secondRunPriceCount = ProductPrice::count();

        $this->assertSame($firstRunPriceCount, $secondRunPriceCount);
    }

    public function test_stale_plans_are_removed_on_rerun(): void
    {
        $this->runSeeders();

        // Manually inject a stale plan that is NOT in the 2026 spec
        $product = Product::where('code', 'macro_sales_crm')->firstOrFail();
        ProductPlan::create([
            'product_id' => $product->id,
            'code' => 'stale_old_plan',
            'name' => 'Stale Old Plan',
            'unit' => 'year',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        // macro_sales_crm has no plans in the spec (base prices only),
        // so re-running should delete the stale plan
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseMissing('catalog_product_plans', [
            'product_id' => $product->id,
            'code' => 'stale_old_plan',
        ]);
    }

    // ── Amount type safety (integer, not float) ────────────────────────────────

    public function test_all_price_amounts_are_integers(): void
    {
        $this->runSeeders();

        ProductPrice::all()->each(function (ProductPrice $price): void {
            $this->assertIsInt($price->amount, "Amount for price #{$price->id} must be integer");
            $this->assertGreaterThan(0, $price->amount, "Amount for price #{$price->id} must be positive");
        });
    }
}
