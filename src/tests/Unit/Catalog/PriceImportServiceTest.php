<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\PriceImportService;
use App\Domain\Catalog\Services\ProductGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PriceImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PriceImportService(new ProductGroupService);
    }

    /**
     * Build a minimal xlsx UploadedFile for testing.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildExcel(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['code', 'name', 'currency_code', 'amount'];
        $sheet->fromArray([$headers], null, 'A1');

        foreach ($rows as $i => $row) {
            $rowData = [];
            foreach ($headers as $header) {
                $rowData[] = $row[$header] ?? '';
            }
            $sheet->fromArray([$rowData], null, 'A'.($i + 2));
        }

        $path = sys_get_temp_dir().'/unit_import_test_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_parse_row_converts_amount_to_kopecks(): void
    {
        $file = $this->buildExcel([
            ['code' => 'unit_prod_1', 'name' => 'Unit Product', 'currency_code' => 'KZT', 'amount' => 1500.00],
        ]);

        $result = $this->service->importFromExcel($file);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, count($result->errors));

        // 1500.00 × 100 = 150000 kopecks.
        $this->assertDatabaseHas('catalog_product_prices', [
            'currency_code' => 'KZT',
            'amount' => 150000,
        ]);
    }

    public function test_parse_row_unknown_currency_produces_error(): void
    {
        $file = $this->buildExcel([
            ['code' => 'unit_prod_bad', 'name' => 'Bad Currency', 'currency_code' => 'ZZZ', 'amount' => 100],
        ]);

        $result = $this->service->importFromExcel($file);

        $this->assertSame(1, $result->skipped);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('ZZZ', $result->errors[0]['message']);
    }

    public function test_parse_row_missing_code_produces_error(): void
    {
        $file = $this->buildExcel([
            ['code' => '', 'name' => 'Missing Code', 'currency_code' => 'KZT', 'amount' => 100],
        ]);

        $result = $this->service->importFromExcel($file);

        $this->assertSame(1, $result->skipped);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('code', $result->errors[0]['message']);
    }
}
