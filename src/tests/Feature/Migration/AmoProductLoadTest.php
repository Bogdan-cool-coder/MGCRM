<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Enums\AmoProductMappingAction;
use App\Domain\Migration\Loaders\MigrationLoader;
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Migration\Models\AmoProductMapping;
use App\Domain\Migration\Models\MigrationMap;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMO product-line load tests — proves amo_product_mappings is consulted by the
 * ETL (MAJOR #1) and that migration_maps is materialised + read back (MAJOR #2).
 * Tiny on-disk JSONL fixtures, SQLite :memory:.
 */
class AmoProductLoadTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $relative = 'amo-product-test-'.uniqid();
        config(['amo_migration.api.staging_path' => $relative]);
        $this->stagingDir = storage_path($relative);
        @mkdir($this->stagingDir, 0775, true);

        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            foreach (glob($this->stagingDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stagingDir);
        }

        parent::tearDown();
    }

    private function seedReferenceData(): void
    {
        User::factory()->create(['email' => 'import-amo@mgcrm.local', 'full_name' => 'Импорт АМО']);

        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);
        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification', 'sort_order' => 4]);
        PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'code' => 'success', 'sort_order' => 13]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $files
     */
    private function writeStaging(array $files): void
    {
        foreach (['leads', 'contacts', 'companies', 'tasks', 'events', 'notes'] as $entity) {
            $rows = $files[$entity] ?? [];
            $lines = array_map(
                static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE),
                $rows,
            );
            file_put_contents($this->stagingDir.'/'.$entity.'.jsonl', implode("\n", $lines)."\n");
        }
    }

    private function loader(): MigrationLoader
    {
        return MigrationLoader::make(new StagingReader($this->stagingDir));
    }

    /**
     * A lead carrying AMO «Продукт» (field 590196) multiselect enum ids.
     *
     * @param  list<int>  $productEnumIds
     */
    private function leadWithProducts(array $productEnumIds, int $id = 1000): void
    {
        $this->writeStaging([
            'leads' => [[
                'id' => $id,
                'name' => 'Deal With Products',
                'price' => 1000,
                'status_id' => 53233417, // qualification
                'pipeline_id' => 6149857,
                'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => ['companies' => [['id' => 5000, 'is_main' => true]], 'contacts' => []],
                'custom_fields_values' => [
                    [
                        'field_id' => 590196,
                        'values' => array_map(
                            static fn (int $enumId): array => ['value' => 'P'.$enumId, 'enum_id' => $enumId],
                            $productEnumIds,
                        ),
                    ],
                ],
            ]],
            'companies' => [['id' => 5000, 'name' => 'ООО Продуктовая']],
        ]);
    }

    public function test_mapped_product_creates_deal_product_line(): void
    {
        $product = Product::factory()->create(['code' => 'macro_sales_crm']);
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => 1_500_00, // kopecks
        ]);
        AmoProductMapping::create([
            'amo_enum_id' => 1125732,
            'amo_value' => '1. MacroCRM',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->leadWithProducts([1125732]);

        $result = $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $line = DealProduct::query()->where('deal_id', $deal->id)->firstOrFail();

        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('RUB', $line->currency);
        $this->assertSame(1_500_00, $line->unit_price, 'unit price snapshotted from the catalog price book');
        $this->assertSame(1, $result['stats']['deal_products']);
        // The imported budget stays locked on the deal — the product line is
        // "for reference" and never re-drives Deal.amount.
        $this->assertTrue($deal->amount_locked);
        $this->assertSame(100000, $deal->amount); // 1000 × 100
    }

    public function test_skip_product_creates_no_line(): void
    {
        AmoProductMapping::create([
            'amo_enum_id' => 1137106,
            'amo_value' => '3. MacroBank',
            'action' => AmoProductMappingAction::Skip->value,
            'catalog_product_id' => null,
        ]);

        $this->leadWithProducts([1137106]);

        $result = $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $this->assertSame(0, DealProduct::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(1, $result['stats']['products_skipped']);
        $this->assertSame(0, $result['stats']['deal_products']);
    }

    public function test_uncurated_product_option_is_tallied_unmapped_not_loaded(): void
    {
        // No amo_product_mappings row for enum 999999 (a NEW AMO option).
        $this->leadWithProducts([999999]);

        $result = $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $this->assertSame(0, DealProduct::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(1, $result['stats']['products_unmapped']);
        $this->assertSame(1, $result['unmapped']['product']['999999']);
    }

    public function test_resolution_is_materialised_into_migration_maps(): void
    {
        $product = Product::factory()->create(['code' => 'macro_erp']);
        AmoProductMapping::create([
            'amo_enum_id' => 1125734,
            'amo_value' => '2. MacroERP',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->leadWithProducts([1125734]);

        $this->assertSame(0, MigrationMap::query()->count());

        $this->loader()->load();

        // The product_option resolution was written to migration_maps (the table
        // is no longer dead — MAJOR #2).
        $cached = MigrationMap::query()
            ->where('map_type', 'product_option')
            ->where('amo_id', '1125734')
            ->firstOrFail();

        $this->assertSame('map', $cached->target_code);
        $this->assertSame($product->id, (int) $cached->target_id);
        $this->assertSame('590196', $cached->amo_parent_id);
    }

    public function test_migration_maps_cache_is_read_back_on_rerun(): void
    {
        // Pre-seed ONLY migration_maps (no amo_product_mappings row): proves the
        // loader reads the runtime cache, not just the curated table.
        $product = Product::factory()->create(['code' => 'touchlink']);
        MigrationMap::create([
            'map_type' => 'product_option',
            'amo_id' => '1203740',
            'amo_parent_id' => '590196',
            'target_code' => 'map',
            'target_id' => $product->id,
            'target_meta' => ['plan_id' => null],
        ]);

        $this->leadWithProducts([1203740]);

        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $line = DealProduct::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($product->id, $line->product_id);
        // amo_product_mappings was never touched — the cache served the resolution.
        $this->assertSame(0, AmoProductMapping::query()->count());
    }

    public function test_reload_does_not_duplicate_product_lines(): void
    {
        $product = Product::factory()->create(['code' => 'macro_catalog']);
        AmoProductMapping::create([
            'amo_enum_id' => 1125736,
            'amo_value' => '4.1 MacroCatalog',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->leadWithProducts([1125736]);

        $this->loader()->load();
        $this->loader()->load(); // re-run

        $deal = Deal::query()->firstOrFail();
        $this->assertSame(1, DealProduct::query()->where('deal_id', $deal->id)->count(), 'no duplicate line on re-load');
    }

    public function test_reload_backfills_missing_product_line(): void
    {
        $product = Product::factory()->create(['code' => 'macro_web']);

        // First load with NO curation row → the deal lands without a product line.
        $this->leadWithProducts([1197702]);
        $this->loader()->load();

        $deal = Deal::query()->firstOrFail();
        $this->assertSame(0, DealProduct::query()->where('deal_id', $deal->id)->count());

        // Curation row is added, the importer re-runs and backfills the line.
        AmoProductMapping::create([
            'amo_enum_id' => 1197702,
            'amo_value' => '4.4 Сайт ЖК',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->loader()->load();

        $this->assertSame(1, DealProduct::query()->where('deal_id', $deal->id)->count(), 're-load backfills the missing line');
    }

    public function test_mapped_product_without_price_lands_zero_unit_price(): void
    {
        $product = Product::factory()->create(['code' => 'ppi']); // no ProductPrice
        AmoProductMapping::create([
            'amo_enum_id' => 1189000,
            'amo_value' => '11 ППИ',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->leadWithProducts([1189000]);

        $this->loader()->load();

        $line = DealProduct::query()->firstOrFail();
        $this->assertSame(0, $line->unit_price);
        $this->assertSame(0, $line->amount);
    }
}
