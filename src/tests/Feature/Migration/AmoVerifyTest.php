<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Loaders\MigrationLoader;
use App\Domain\Migration\Loaders\MigrationVerifier;
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify / dry-run tests — parity counters and the no-write guarantee of the
 * transform (dry-run) phase. Tiny on-disk JSONL fixtures, SQLite :memory:.
 */
class AmoVerifyTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $relative = 'amo-verify-test-'.uniqid();
        config(['amo_migration.api.staging_path' => $relative]);
        $this->stagingDir = storage_path($relative);
        @mkdir($this->stagingDir, 0775, true);

        User::factory()->create(['email' => 'import-amo@mgcrm.local', 'full_name' => 'Импорт АМО']);
        User::factory()->create(['email' => 'b.yadykin@macroprop.tech', 'full_name' => 'B Y']);

        $pipeline = Pipeline::factory()->create(['name' => 'MACRO Global']);
        PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'qualification']);
        PipelineStage::factory()->won()->create(['pipeline_id' => $pipeline->id, 'code' => 'success']);

        $this->writeFixture();
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

    private function writeFixture(): void
    {
        $files = [
            'leads' => [[
                'id' => 1000, 'name' => 'D', 'price' => 100,
                'status_id' => 53233417, 'pipeline_id' => 6149857, 'responsible_user_id' => 2435437,
                '_embedded' => ['companies' => [['id' => 5000]], 'contacts' => [['id' => 7000, 'is_main' => true]]],
            ]],
            'companies' => [['id' => 5000, 'name' => 'Co']],
            'contacts' => [['id' => 7000, 'name' => 'Person']],
            'tasks' => [], 'events' => [], 'notes' => [],
        ];

        foreach (['leads', 'contacts', 'companies', 'tasks', 'events', 'notes'] as $entity) {
            $rows = $files[$entity] ?? [];
            $lines = array_map(static fn (array $r): string => (string) json_encode($r), $rows);
            file_put_contents($this->stagingDir.'/'.$entity.'.jsonl', $lines === [] ? '' : implode("\n", $lines)."\n");
        }
    }

    private function loader(): MigrationLoader
    {
        return MigrationLoader::make(new StagingReader($this->stagingDir));
    }

    public function test_dry_run_writes_nothing_but_reports_counts(): void
    {
        $result = $this->loader()->load(['dry_run' => true]);

        // Nothing persisted.
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Company::query()->count());

        // But the report shows what WOULD be created.
        $this->assertSame(1, $result['stats']['deals_created']);
        $this->assertSame(1, $result['stats']['companies_created']);
        $this->assertSame(1, $result['stats']['contacts_created']);
    }

    public function test_verify_parity_matches_after_load(): void
    {
        $this->loader()->load();

        $verifier = new MigrationVerifier(new StagingReader($this->stagingDir));
        $report = $verifier->verify(spotCheckCount: 3);

        $this->assertSame(1, $report['parity']['deals']['staging']);
        $this->assertSame(1, $report['parity']['deals']['loaded']);
        $this->assertSame(0, $report['parity']['deals']['diff']);

        $this->assertSame(1, $report['parity']['contacts']['loaded']);

        // Spot-check returns the loaded deal with resolved fields.
        $this->assertNotEmpty($report['spot_checks']);
        $this->assertSame('D', $report['spot_checks'][0]['title']);
        $this->assertSame('Co', $report['spot_checks'][0]['company']);
    }

    public function test_verify_parity_shows_diff_when_nothing_loaded(): void
    {
        $verifier = new MigrationVerifier(new StagingReader($this->stagingDir));
        $report = $verifier->verify(spotCheckCount: 0);

        $this->assertSame(1, $report['parity']['deals']['staging']);
        $this->assertSame(0, $report['parity']['deals']['loaded']);
        $this->assertSame(1, $report['parity']['deals']['diff']);
    }
}
