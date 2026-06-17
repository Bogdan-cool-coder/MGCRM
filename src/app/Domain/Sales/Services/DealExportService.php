<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * DealExportService — XLSX export of the filtered, visibility-scoped deal list
 * (Сделки-борд: экспорт). Reuses DealService::filteredQuery so the export honours
 * exactly the same board filters and the same row-level scope the user sees — a
 * manager never exports a foreign department's deals.
 *
 * Uses the PhpSpreadsheet v5 A1-notation API (setCellValue), mirroring
 * SalesDashboardService::buildXlsx. Amounts are exported both in raw kopecks and
 * in major units (kopecks / 100) so finance keeps the exact integer alongside a
 * readable figure.
 */
class DealExportService
{
    /** Rows are streamed in chunks to keep memory flat on large funnels. */
    private const CHUNK = 500;

    public function __construct(
        private readonly DealService $dealService,
    ) {}

    private const HEADERS = [
        'ID',
        'Title',
        'Pipeline',
        'Stage',
        'Company',
        'Owner',
        'Amount (kopecks)',
        'Amount',
        'Currency',
        'Status',
        'Tags',
        'Expected close',
        'Created at',
    ];

    /**
     * Build the XLSX file bytes for the given filters under the user's scope.
     *
     * @param  array<string, mixed>  $filters
     */
    public function buildXlsx(array $filters, VisibilityScope $scope, User $user): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Deals');

        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValue($this->addr($col + 1, 1), $header);
        }

        $row = 2;

        $this->dealService->filteredQuery($filters, $scope, $user)
            ->chunkById(self::CHUNK, function ($deals) use ($sheet, &$row): void {
                foreach ($deals as $deal) {
                    $this->writeRow($sheet, $deal, $row);
                    $row++;
                }
            });

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function writeRow(Worksheet $sheet, Deal $deal, int $row): void
    {
        $values = [
            $deal->id,
            $deal->title,
            $deal->pipeline?->name,
            $deal->stage?->name,
            $deal->company?->name,
            $deal->owner?->full_name,
            $deal->amount, // kopecks
            $deal->amount / 100,
            $deal->currency,
            $deal->status(),
            implode(', ', $deal->tags ?? []),
            $deal->expected_close_date?->toDateString(),
            $deal->created_at?->toDateTimeString(),
        ];

        foreach ($values as $col => $value) {
            $sheet->setCellValue($this->addr($col + 1, $row), $value);
        }
    }

    /** Convert 1-based column + row to A1 notation (PhpSpreadsheet v5+). */
    private function addr(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col).$row;
    }
}
