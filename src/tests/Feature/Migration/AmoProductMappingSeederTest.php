<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Migration\Models\AmoProductMapping;
use Database\Seeders\AmoProductMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AmoProductMappingSeeder pre-loads the 94 AMO product enum options as skip rows
 * and is idempotent without clobbering human curation.
 */
class AmoProductMappingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_94_product_options_as_skip_rows(): void
    {
        $this->seed(AmoProductMappingSeeder::class);

        $this->assertSame(94, AmoProductMapping::count());

        // Every freshly-seeded row defaults to skip + null catalog FKs (uncurated).
        $this->assertSame(94, AmoProductMapping::where('action', 'skip')->count());
        $this->assertSame(0, AmoProductMapping::whereNotNull('catalog_product_id')->count());

        // Spot-check a couple of known options.
        $this->assertDatabaseHas('amo_product_mappings', [
            'amo_enum_id' => 1125732,
            'amo_value' => '1.  MacroCRM (база)',
        ]);
        $this->assertDatabaseHas('amo_product_mappings', [
            'amo_enum_id' => 1203740,
            'amo_value' => '8.26 TouchLink',
        ]);
    }

    public function test_rerun_is_idempotent_and_preserves_curation(): void
    {
        $this->seed(AmoProductMappingSeeder::class);

        // Simulate a human curating one option (action + notes; the catalog FK is
        // exercised in app code, here we keep it null to avoid needing a catalog row).
        AmoProductMapping::where('amo_enum_id', 1125732)->update([
            'action' => 'map',
            'notes' => 'curated by hand',
        ]);

        // Re-run.
        $this->seed(AmoProductMappingSeeder::class);

        // Still exactly 94 rows — no duplicates.
        $this->assertSame(94, AmoProductMapping::count());

        // Curation survived the re-run (only amo_value is re-asserted).
        $curated = AmoProductMapping::where('amo_enum_id', 1125732)->firstOrFail();
        $this->assertSame('map', $curated->action);
        $this->assertSame('curated by hand', $curated->notes);
    }
}
