<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Catalog\Models\Product;
use App\Domain\Migration\Models\AmoProductMapping;
use Database\Seeders\AmoProductMappingSeeder;
use Database\Seeders\ProductGroupSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AmoProductMappingSeeder curated mapping validation.
 *
 * Expected counts (2026-06-22 curation):
 *   map  — 25 (macro_sales_crm×2, macro_erp×3, flowfix×5, macro_catalog,
 *              customer_portal×2, macro_broker×3, macro_web×2, touchlink,
 *              ppi×4, voice_ai_broker, data_analytics_ai)
 *   skip — 69
 *   total — 94
 *
 * All mapped rows must point to an existing Product row.
 * UNCERTAIN rows carry a notes string containing 'UNCERTAIN'.
 */
class AmoProductMappingSeederTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function seedAll(): void
    {
        $this->seed(ProductGroupSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(AmoProductMappingSeeder::class);
    }

    // ── basic row count ───────────────────────────────────────────────────────

    public function test_seeds_exactly_94_rows(): void
    {
        $this->seedAll();

        $this->assertSame(94, AmoProductMapping::count());
    }

    // ── map / skip totals ─────────────────────────────────────────────────────

    public function test_map_and_skip_totals_are_correct(): void
    {
        $this->seedAll();

        $mapped = AmoProductMapping::where('action', 'map')->count();
        $skipped = AmoProductMapping::where('action', 'skip')->count();

        // 25 mapped + 69 skipped = 94
        $this->assertSame(25, $mapped, "Expected 25 map rows, got {$mapped}");
        $this->assertSame(69, $skipped, "Expected 69 skip rows, got {$skipped}");
    }

    // ── every map row has a valid catalog_product_id ─────────────────────────

    public function test_all_mapped_rows_have_valid_catalog_product_id(): void
    {
        $this->seedAll();

        $mapped = AmoProductMapping::where('action', 'map')->get();

        foreach ($mapped as $row) {
            $this->assertNotNull(
                $row->catalog_product_id,
                "Mapped row {$row->amo_value} (enum {$row->amo_enum_id}) has null catalog_product_id",
            );

            $exists = Product::where('id', $row->catalog_product_id)->exists();
            $this->assertTrue(
                $exists,
                "Mapped row {$row->amo_value} points to non-existent product id {$row->catalog_product_id}",
            );
        }
    }

    // ── skip rows have no catalog FK ─────────────────────────────────────────

    public function test_skip_rows_have_no_catalog_product_id(): void
    {
        $this->seedAll();

        $badSkips = AmoProductMapping::where('action', 'skip')
            ->whereNotNull('catalog_product_id')
            ->count();

        $this->assertSame(0, $badSkips, 'Some skip rows unexpectedly have a catalog_product_id');
    }

    // ── specific product mappings ─────────────────────────────────────────────

    public function test_macro_crm_base_maps_to_macro_sales_crm(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1125732)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_sales_crm', $product->code);
    }

    public function test_macro_sales_basic_maps_to_macro_sales_crm(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1198158)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_sales_crm', $product->code);
    }

    public function test_macro_erp_base_maps_to_macro_erp(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1125734)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_erp', $product->code);
    }

    public function test_macro_bank_base_maps_to_flowfix_with_uncertain_note(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1137106)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('flowfix', $product->code);

        $this->assertNotNull($row->notes);
        $this->assertStringContainsString('UNCERTAIN', $row->notes);
    }

    public function test_touchlink_maps_to_touchlink(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1203740)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('touchlink', $product->code);
    }

    public function test_macro_catalog_module_maps_to_macro_catalog(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1125736)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_catalog', $product->code);
    }

    public function test_kabinet_klienta_maps_to_customer_portal(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1125756)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('customer_portal', $product->code);
    }

    public function test_kabinet_agenta_maps_to_macro_broker(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1125754)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_broker', $product->code);
    }

    public function test_site_gk_maps_to_macro_web(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1197702)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('macro_web', $product->code);
    }

    public function test_ppi_maps_to_ppi(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1189000)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('ppi', $product->code);
    }

    public function test_all_ppi_subtypes_map_to_ppi(): void
    {
        $this->seedAll();

        $ppiEnumIds = [1189000, 1200502, 1200504, 1200506];

        foreach ($ppiEnumIds as $enumId) {
            $row = AmoProductMapping::where('amo_enum_id', $enumId)->firstOrFail();
            $product = Product::find($row->catalog_product_id);

            $this->assertSame('map', $row->action, "PPI row {$enumId} should be map");
            $this->assertNotNull($product);
            $this->assertSame('ppi', $product->code, "PPI row {$enumId} should map to ppi");
        }
    }

    public function test_voice_ai_broker_mapping_has_uncertain_note(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1193558)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('voice_ai_broker', $product->code);

        $this->assertNotNull($row->notes);
        $this->assertStringContainsString('UNCERTAIN', $row->notes);
    }

    public function test_data_analytics_ai_mapping_has_uncertain_note(): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', 1198070)->firstOrFail();
        $this->assertSame('map', $row->action);

        $product = Product::find($row->catalog_product_id);
        $this->assertNotNull($product);
        $this->assertSame('data_analytics_ai', $product->code);

        $this->assertNotNull($row->notes);
        $this->assertStringContainsString('UNCERTAIN', $row->notes);
    }

    // ── spot-check important skips ────────────────────────────────────────────

    #[DataProvider('skippedEnumIds')]
    public function test_expected_skips_are_skip(int $enumId, string $label): void
    {
        $this->seedAll();

        $row = AmoProductMapping::where('amo_enum_id', $enumId)->firstOrFail();
        $this->assertSame(
            'skip',
            $row->action,
            "Expected {$label} (enum {$enumId}) to be skip",
        );
        $this->assertNull($row->catalog_product_id);
    }

    /** @return list<array{0: int, 1: string}> */
    public static function skippedEnumIds(): array
    {
        return [
            [1125740, 'СНЯТ С ПРОДАЖИ ПРОЕКТНОЕ ФИНАНСИРОВАНИЕ'],
            [1125738, '4.5. MacroTender'],
            [1125744, '4.6. MacroPlan'],
            [1188218, '8.2. Сделка.рф'],
            [1200678, '8.11. Wazzup'],
            [1203444, '8.25 СБИС'],
            [1198196, '14. Scrumo'],
            [1189140, '12. Продление подписки'],
            [1188158, '10.1. Поднятие на 10%'],
            [1137246, 'не определен'],
            [1125750, '9.1. Учетные записи (ОС)'],
            [1189168, '13.2. Доработки CRM'],
            [1200644, 'СНЯТ С ПРОДАЖИ Macro 3D'],
        ];
    }

    // ── idempotency ───────────────────────────────────────────────────────────

    public function test_rerun_is_idempotent(): void
    {
        $this->seedAll();

        // Re-seed twice more.
        $this->seed(AmoProductMappingSeeder::class);
        $this->seed(AmoProductMappingSeeder::class);

        $this->assertSame(94, AmoProductMapping::count());
        $this->assertSame(25, AmoProductMapping::where('action', 'map')->count());
        $this->assertSame(69, AmoProductMapping::where('action', 'skip')->count());
    }

    public function test_rerun_overwrites_manual_deviation(): void
    {
        $this->seedAll();

        // Manually corrupt one mapped row.
        AmoProductMapping::where('amo_enum_id', 1125732)->update([
            'action' => 'skip',
            'catalog_product_id' => null,
        ]);

        // Re-seed restores canonical mapping.
        $this->seed(AmoProductMappingSeeder::class);

        $row = AmoProductMapping::where('amo_enum_id', 1125732)->firstOrFail();
        $this->assertSame('map', $row->action);
        $this->assertNotNull($row->catalog_product_id);
    }
}
