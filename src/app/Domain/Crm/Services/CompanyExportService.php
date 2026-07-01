<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
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

    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

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
        'Author',
        'Tags',
        'Last Activity',
        'Created At',
    ];

    /**
     * Build XLSX bytes for the given company IDs, scoped to what $actor may see.
     *
     * When $companyIds is non-empty, the export is restricted to that subset
     * intersected with the actor's visibility scope (selected-rows export).
     * When $companyIds is empty, exports the actor's entire visible set — never
     * the full table regardless of who calls this method.
     *
     * @param  list<int>  $companyIds
     */
    public function buildXlsx(array $companyIds, User $actor): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Companies');

        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValue($this->addr($col + 1, 1), $header);
        }

        $row = 2;

        // Apply row-level visibility scope first — the actor can never export
        // companies they cannot list. Then narrow to the requested id subset.
        $this->visibility->applyScope(
            Company::query(),
            $actor,
            ['owner_user_id', 'responsible_user_id'],
        )
            ->when($companyIds !== [], static fn ($q) => $q->whereIn('id', $companyIds))
            ->with(['companyType', 'ownerUser', 'responsibleUser', 'creator'])
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
            $company->creator?->full_name,    // Author column (immutable creator)
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
