<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Enums\AmoProductMappingAction;
use App\Domain\Migration\Loaders\MigrationLoader;
use App\Domain\Migration\Loaders\RollbackLoader;
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Migration\Models\AmoProductMapping;
use App\Domain\Migration\Models\ExternalRef;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMO rollback tests — proves `amo:migrate rollback` undoes a load via
 * external_refs in reverse FK order (MINOR #3), with a --dry-run preview that
 * writes nothing and leaves hand-created entities untouched.
 */
class AmoRollbackTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $relative = 'amo-rollback-test-'.uniqid();
        config(['amo_migration.api.staging_path' => $relative]);
        $this->stagingDir = storage_path($relative);
        @mkdir($this->stagingDir, 0775, true);

        User::factory()->create(['email' => 'import-amo@mgcrm.local', 'full_name' => 'Импорт АМО']);

        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);
        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification', 'sort_order' => 4]);
        PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'code' => 'success', 'sort_order' => 13]);

        $product = Product::factory()->create(['code' => 'macro_sales_crm']);
        AmoProductMapping::create([
            'amo_enum_id' => 1125732,
            'amo_value' => '1. MacroCRM',
            'action' => AmoProductMappingAction::Map->value,
            'catalog_product_id' => $product->id,
        ]);

        $this->seedFixtureAndLoad();
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

    private function seedFixtureAndLoad(): void
    {
        $files = [
            'leads' => [[
                'id' => 1000, 'name' => 'Rollback Deal', 'price' => 2500,
                'status_id' => 53233417, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                'created_at' => 1577836800,
                '_embedded' => [
                    'companies' => [['id' => 5000, 'is_main' => true]],
                    'contacts' => [['id' => 7000, 'is_main' => true]],
                ],
                'custom_fields_values' => [
                    ['field_id' => 590196, 'values' => [['value' => 'P', 'enum_id' => 1125732]]],
                ],
            ]],
            'companies' => [['id' => 5000, 'name' => 'ООО Откат']],
            'contacts' => [['id' => 7000, 'name' => 'Контакт Откатов']],
            'tasks' => [[
                'id' => 9001, 'entity_type' => 'leads', 'entity_id' => 1000,
                'task_type_id' => 1, 'text' => 'Позвонить', 'complete_till' => 1577923200,
                'is_completed' => false, 'created_at' => 1577836800, 'responsible_user_id' => 2435437,
            ]],
            'events' => [
                ['id' => 'e1', 'type' => 'lead_added', '_lead_id' => 1000, 'created_at' => 1577836800, 'created_by' => 2435437],
            ],
            'notes' => [],
        ];

        foreach (['leads', 'contacts', 'companies', 'tasks', 'events', 'notes'] as $entity) {
            $rows = $files[$entity] ?? [];
            $lines = array_map(static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE), $rows);
            file_put_contents($this->stagingDir.'/'.$entity.'.jsonl', $lines === [] ? '' : implode("\n", $lines)."\n");
        }

        MigrationLoader::make(new StagingReader($this->stagingDir))->load();
    }

    public function test_rollback_dry_run_writes_nothing_but_reports_counts(): void
    {
        $dealsBefore = Deal::query()->count();
        $refsBefore = ExternalRef::query()->count();

        $counts = (new RollbackLoader)->rollback(['dry_run' => true]);

        // Nothing deleted.
        $this->assertSame($dealsBefore, Deal::query()->count());
        $this->assertSame($refsBefore, ExternalRef::query()->count());

        // But the report shows what WOULD be deleted.
        $this->assertTrue($counts['dry_run']);
        $this->assertSame(1, $counts['deals']);
        $this->assertSame(1, $counts['contacts']);
        $this->assertSame(1, $counts['companies']);
        $this->assertSame(1, $counts['activities']);
        $this->assertGreaterThanOrEqual(1, $counts['entity_logs']);
        $this->assertGreaterThanOrEqual(3, $counts['external_refs']); // deal+contact+company+activity
    }

    public function test_rollback_deletes_imported_graph(): void
    {
        $deal = Deal::query()->firstOrFail();

        $this->assertSame(1, DealProduct::query()->where('deal_id', $deal->id)->count());

        $counts = (new RollbackLoader)->rollback();

        $this->assertFalse($counts['dry_run']);

        // The whole imported graph is gone.
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ExternalRef::query()->count());

        // Cascaded children are gone too.
        $this->assertSame(0, DealProduct::query()->count());
        $this->assertSame(0, DB::table('deal_contacts')->count());
        $this->assertSame(0, DB::table('deal_stage_history')->count());
        $this->assertSame(0, DB::table('activities')->count());
        $this->assertSame(0, DB::table('entity_logs')->count());
        $this->assertSame(0, DB::table('company_channels')->count());
        $this->assertSame(0, DB::table('company_requisites')->count());
    }

    public function test_rollback_leaves_hand_created_entities_untouched(): void
    {
        // A native MGCRM company with NO external_ref must survive the rollback.
        $native = Company::factory()->create(['name' => 'Родная компания']);

        (new RollbackLoader)->rollback();

        $this->assertNotNull(Company::query()->find($native->id), 'a hand-created company must survive rollback');
        $this->assertSame(0, ExternalRef::query()->count());
    }

    public function test_rollback_then_reload_restores_graph(): void
    {
        (new RollbackLoader)->rollback();
        $this->assertSame(0, Deal::query()->count());

        // Re-running the load after a rollback rebuilds the graph cleanly.
        MigrationLoader::make(new StagingReader($this->stagingDir))->load();

        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(1, DealProduct::query()->count());
        $this->assertSame(1, ExternalRef::query()->where('entity_type', 'deal')->count());
    }
}
