<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * CompanyExportService — XLSX export of companies.
 * Pattern: 1-in-1 with DealExportService (PhpSpreadsheet v5, CHUNK, chunkById).
 *
 * Columns: ID / Name / Legal Name / Tax ID / Company Type / Country /
 *          Industry / Category / Owner / Responsible / Tags / Last Activity / Created At
 */
class CompanyExportService
{
    private const CHUNK = 500;

    private const HEADERS = [
        'ID',
        'Name',
        'Legal Name',
        'Tax ID',
        'Company Type',
        'Country',
        'Industry',
        'Category',
        'Owner',
        'Responsible',
        'Tags',
        'Last Activity',
        'Created At',
    ];

    /**
     * Build XLSX bytes for the given company IDs.
     *
     * @param  list<int>  $companyIds
     */
    public function buildXlsx(array $companyIds): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Companies');

        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValue($this->addr($col + 1, 1), $header);
        }

        $row = 2;

        Company::query()
            ->when($companyIds !== [], static fn ($q) => $q->whereIn('id', $companyIds))
            ->with(['companyType', 'ownerUser', 'responsibleUser'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($companies) use ($sheet, &$row): void {
                foreach ($companies as $company) {
                    $this->writeRow($sheet, $company, $row);
                    $row++;
                }
            });

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function writeRow(Worksheet $sheet, Company $company, int $row): void
    {
        $values = [
            $company->id,
            $company->name,
            $company->legal_name,
            $company->tax_id,
            $company->companyType?->name,
            $company->country_code,
            $company->industry,
            $company->category_code?->value,
            $company->ownerUser?->full_name,
            $company->responsibleUser?->full_name,
            implode(', ', $company->tags ?? []),
            $company->last_activity_at?->toDateTimeString(),
            $company->created_at?->toDateTimeString(),
        ];

        foreach ($values as $col => $value) {
            $sheet->setCellValue($this->addr($col + 1, $row), $value);
        }
    }

    private function addr(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col).$row;
    }
}
