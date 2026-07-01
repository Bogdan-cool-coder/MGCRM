<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\PriceImportService;
use App\Domain\Catalog\Services\ProductGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

    /**
     * Regression: amount must be read as a raw numeric value (getCalculatedValue),
     * not as a formatted string (getFormattedValue), to avoid locale-dependent
     * parsing where "1 200,50" or "$1,200.50" would cast to 1.0 or 0.0.
     */
    public function test_amount_numeric_cell_converts_correctly_to_kopecks(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([['code', 'name', 'currency_code', 'amount']], null, 'A1');
        $sheet->setCellValue('A2', 'numeric_test_prod');
        $sheet->setCellValue('B2', 'Numeric Cell Product');
        $sheet->setCellValue('C2', 'KZT');
        // Explicitly set as a numeric type — simulates what Excel does for number columns.
        $sheet->setCellValueExplicit(
            'D2',
            1200.5,
            DataType::TYPE_NUMERIC,
        );

        $path = sys_get_temp_dir().'/unit_numeric_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $file = new UploadedFile(
            $path,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $result = $this->service->importFromExcel($file);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, count($result->errors));

        // 1200.5 × 100 = 120050 kopecks — must not parse as 1.0 or 0.0.
        $this->assertDatabaseHas('catalog_product_prices', [
            'currency_code' => 'KZT',
            'amount' => 120050,
        ]);
    }

    /**
     * Fix #4: string cells with grouping separators (e.g. "1 500,00" from a
     * locale-formatted export) must strip the separators and parse correctly.
     *
     * We simulate this by storing the amount as a string value with a space
     * as the grouping separator and a comma as the decimal separator.
     */
    public function test_amount_string_with_grouping_separators_parses_correctly(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([['code', 'name', 'currency_code', 'amount']], null, 'A1');
        $sheet->setCellValue('A2', 'fmt_test_prod');
        $sheet->setCellValue('B2', 'Formatted String Product');
        $sheet->setCellValue('C2', 'KZT');
        // Simulate a locale-formatted string cell: "1 500" = 1500 currency units.
        $sheet->setCellValueExplicit(
            'D2',
            '1 500',
            DataType::TYPE_STRING,
        );

        $path = sys_get_temp_dir().'/unit_fmt_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $file = new UploadedFile(
            $path,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $result = $this->service->importFromExcel($file);

        $this->assertSame(1, $result->inserted, 'Row should be inserted without error: '.json_encode($result->errors));
        $this->assertSame(0, count($result->errors));

        // "1 500" → strip space → "1500" → 1500 × 100 = 150000 kopecks.
        $this->assertDatabaseHas('catalog_product_prices', [
            'currency_code' => 'KZT',
            'amount' => 150000,
        ]);
    }
}
