<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * GET /api/deals/export — XLSX download of the filtered, visibility-scoped list.
 */
class DealExportTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_export_returns_xlsx_download_headers(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Exportable deal',
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->get('/api/deals/export');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('deals.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_export_contains_filtered_rows(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $director = User::factory()->create(['role' => Role::Director]);
        Deal::factory()->forOwner($director)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Findme deal',
            'amount' => 123456,
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($director, ['*']);

        $bytes = $this->get('/api/deals/export')->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'deals').'.xlsx';
        file_put_contents($path, $bytes);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $cells = $sheet->toArray();

        @unlink($path);

        // Header row + at least one data row.
        $this->assertSame('ID', $cells[0][0]);
        $this->assertSame('Title', $cells[0][1]);

        $flat = array_map(static fn ($v): string => (string) $v, array_merge(...$cells));
        $this->assertContains('Findme deal', $flat);
        // Raw kopecks column carries the exact integer amount.
        $this->assertContains('123456', $flat);
    }

    public function test_export_respects_visibility_scope(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Mine only',
        ]);
        Deal::factory()->forOwner($other)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Foreign secret',
        ]);

        Sanctum::actingAs($owner, ['*']);

        $bytes = $this->get('/api/deals/export')->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'deals').'.xlsx';
        file_put_contents($path, $bytes);
        $flat = array_map(
            static fn ($v): string => (string) $v,
            array_merge(...IOFactory::load($path)->getActiveSheet()->toArray()),
        );
        @unlink($path);

        $this->assertContains('Mine only', $flat);
        $this->assertNotContains('Foreign secret', $flat);
    }
}
