<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PriceTemplateService — generates a sample .xlsx for the Excel price-import flow.
 *
 * The template contains:
 *  - Row 1: column headers matching what PriceImportService expects.
 *  - Row 2: one example data row so the user understands the expected format.
 *
 * Columns: code | name | description | group | pricing_type | plan_name | plan_unit
 *          | plan_code | currency_code | amount | sort_order | is_active
 */
class PriceTemplateService
{
    /** @var list<string> */
    private static array $headers = [
        'code',
        'name',
        'description',
        'group',
        'pricing_type',
        'plan_name',
        'plan_unit',
        'plan_code',
        'currency_code',
        'amount',
        'sort_order',
        'is_active',
    ];

    /** @var list<string> Example row values — one per header column. */
    private static array $exampleRow = [
        'PRODUCT_CODE',
        'Product Name',
        'Optional description',
        'Product Group Name',
        'fixed',
        '',
        'year',
        '',
        'KZT',
        '150000',
        '0',
        '1',
    ];

    /**
     * Build the template and return raw xlsx bytes.
     * Use this in controllers so Laravel's test client can capture the body.
     */
    public function buildBytes(): string
    {
        $spreadsheet = $this->buildSpreadsheet();

        ob_start();
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @deprecated Use buildBytes() and return a plain Response instead.
     *             StreamedResponse cannot be captured by Laravel's test client.
     */
    public function streamTemplateResponse(): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet();

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $filename = 'price_import_template.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    private function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Price Import');

        // Header row (row 1).
        foreach (self::$headers as $col => $header) {
            $colLetter = Coordinate::stringFromColumnIndex($col + 1);
            $cell = $sheet->getCell($colLetter.'1');
            $cell->setValue($header);
            // Bold + light-blue background.
            $sheet->getStyle($colLetter.'1')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFD0E4F7'],
                ],
            ]);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Example data row (row 2).
        foreach (self::$exampleRow as $col => $value) {
            $colLetter = Coordinate::stringFromColumnIndex($col + 1);
            $sheet->getCell($colLetter.'2')->setValue($value);
        }

        return $spreadsheet;
    }
}
