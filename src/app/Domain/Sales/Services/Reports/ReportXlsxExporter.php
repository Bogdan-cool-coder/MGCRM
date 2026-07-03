<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ReportXlsxExporter — shared PhpSpreadsheet xlsx builder for the Ф5 report
 * exports (contract §Excel export). Reuses the A1-notation `setCellValue`
 * pattern from `SalesDashboardService::buildXlsx` (reuse-gate, never a second
 * xlsx-writing idiom in the codebase) — SpaceCRM only exports PNG for these
 * reports, so a downloadable .xlsx with the SAME visibility-scoped numbers as
 * the JSON payload is this sprint's advantage over it.
 *
 * Each report aggregator's payload shape differs (registry = expected/squeeze
 * lists, matrix-style reports = rows×columns×cells grids, best-manager = a
 * flat ranked list, conversions = custom/stage blocks) so this exporter
 * offers one small, focused builder method per shape rather than a single
 * generic "dump any array" writer — money stays formatted as kopecks→RUB the
 * same way `buildXlsx` already does, and every sheet gets a filter-header row
 * so an exported file is self-describing without the JSON meta block.
 */
class ReportXlsxExporter
{
    /**
     * Matrix-style report (P-1 / R4 task-matrix / R6 product-income): rows
     * keyed by a scope label, 12 months + annual columns, one sheet per
     * "group" (task-matrix has one group per kind; product-income / P-1 have
     * a single implicit group).
     *
     * @param  array<string, mixed>  $meta  filter/context values for the header block
     * @param  list<array{key: string, label: string, period_month: int|null}>  $columns
     * @param  list<array{scope: array<string, mixed>, cells: array<string, array<string, mixed>>}>  $rows
     * @param  array<string, array<string, mixed>>  $totals
     * @param  list<string>  $valueFields  cell keys to render as extra columns per month (e.g. ['plan_kopecks','fact_kopecks','pct'])
     * @param  array<string, string>  $valueLabels  field => column header label
     */
    public function buildMatrixSheet(
        Spreadsheet $spreadsheet,
        string $sheetTitle,
        array $meta,
        array $columns,
        array $rows,
        array $totals,
        array $valueFields,
        array $valueLabels,
        bool $isFirstSheet = true,
    ): void {
        $sheet = $isFirstSheet ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($this->safeSheetTitle($sheetTitle));
        $sheet->freezePane('B3');

        $filterRow = $this->writeFilterHeader($sheet, $meta);
        $headerRow = $filterRow + 1;

        // Header row: "Scope" then one sub-header per (column × valueField).
        $sheet->setCellValue($this->addr(1, $headerRow), 'Scope');
        $col = 2;

        foreach ($columns as $column) {
            foreach ($valueFields as $field) {
                $label = ($valueLabels[$field] ?? $field).' — '.$column['label'];
                $sheet->setCellValue($this->addr($col, $headerRow), $label);
                $sheet->getStyle($this->addr($col, $headerRow))->getFont()->setBold(true);
                $col++;
            }
        }

        $r = $headerRow + 1;

        foreach ($rows as $row) {
            $sheet->setCellValue($this->addr(1, $r), $row['scope']['label'] ?? '');
            $col = 2;

            foreach ($columns as $column) {
                $cell = $row['cells'][$column['key']] ?? [];

                foreach ($valueFields as $field) {
                    $this->writeValueCell($sheet, $col, $r, $field, $cell[$field] ?? null);
                    $col++;
                }
            }

            $r++;
        }

        // ИТОГО row.
        $sheet->setCellValue($this->addr(1, $r), 'ИТОГО');
        $sheet->getStyle($this->addr(1, $r))->getFont()->setBold(true);
        $col = 2;

        foreach ($columns as $column) {
            $cell = $totals[$column['key']] ?? [];

            foreach ($valueFields as $field) {
                $this->writeValueCell($sheet, $col, $r, $field, $cell[$field] ?? null);
                $sheet->getStyle($this->addr($col, $r))->getFont()->setBold(true);
                $col++;
            }
        }

        $this->autoSizeColumns($sheet, $col - 1);
    }

    /**
     * Registry-style report (R1): a list of flat rows with named columns.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $columnMap  data-key => header label, in display order
     */
    public function buildListSheet(
        Spreadsheet $spreadsheet,
        string $sheetTitle,
        array $meta,
        array $rows,
        array $columnMap,
        bool $isFirstSheet = true,
    ): void {
        $sheet = $isFirstSheet ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($this->safeSheetTitle($sheetTitle));
        $sheet->freezePane('A3');

        $filterRow = $this->writeFilterHeader($sheet, $meta);
        $headerRow = $filterRow + 1;

        $keys = array_keys($columnMap);

        foreach (array_values($columnMap) as $i => $label) {
            $addr = $this->addr($i + 1, $headerRow);
            $sheet->setCellValue($addr, $label);
            $sheet->getStyle($addr)->getFont()->setBold(true);
        }

        foreach ($rows as $ri => $row) {
            $r = $headerRow + 1 + $ri;

            foreach ($keys as $ci => $key) {
                $value = $row[$key] ?? null;
                $addr = $this->addr($ci + 1, $r);

                if (str_ends_with($key, '_kopecks') && is_int($value)) {
                    $sheet->setCellValue($addr, $value / 100);
                    $sheet->getStyle($addr)->getNumberFormat()->setFormatCode('#,##0.00');

                    continue;
                }

                $sheet->setCellValue($addr, is_scalar($value) || $value === null ? $value : json_encode($value));
            }
        }

        $this->autoSizeColumns($sheet, count($keys));
    }

    /**
     * Write a small "filter header" block (context the JSON payload carries as
     * `meta`) at the top of a sheet so an exported file is self-describing —
     * key: value pairs, one per row, before the data table starts.
     *
     * @param  array<string, mixed>  $meta
     * @return int the last row number used by the filter header (data starts at +1)
     */
    private function writeFilterHeader(Worksheet $sheet, array $meta): int
    {
        $r = 1;

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                continue; // nested meta (e.g. period{}) — skip, top-level scalars only
            }

            $sheet->setCellValue($this->addr(1, $r), (string) $key);
            $sheet->setCellValue($this->addr(2, $r), is_bool($value) ? ($value ? 'true' : 'false') : $value);
            $sheet->getStyle($this->addr(1, $r))->getFont()->setItalic(true);
            $r++;
        }

        return max($r, 1);
    }

    private function writeValueCell(Worksheet $sheet, int $col, int $row, string $field, mixed $value): void
    {
        $addr = $this->addr($col, $row);

        if ($value === null) {
            $sheet->setCellValue($addr, null);

            return;
        }

        if (str_ends_with($field, '_kopecks') && is_int($value)) {
            $sheet->setCellValue($addr, $value / 100);
            $sheet->getStyle($addr)->getNumberFormat()->setFormatCode('#,##0.00');

            return;
        }

        $sheet->setCellValue($addr, is_scalar($value) ? $value : json_encode($value));
    }

    private function autoSizeColumns(Worksheet $sheet, int $lastCol): void
    {
        foreach (range(1, max($lastCol, 1)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }
    }

    /**
     * PhpSpreadsheet sheet titles are capped at 31 chars and forbid several
     * characters ([]:*?/\) — defensive truncation/sanitisation so a report
     * label never throws when used as a sheet title.
     */
    private function safeSheetTitle(string $title): string
    {
        $safe = preg_replace('/[\[\]:\*\?\/\\\\]/', ' ', $title) ?? $title;

        return mb_substr(trim($safe), 0, 31);
    }

    private function addr(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col).$row;
    }

    public function toBytes(Spreadsheet $spreadsheet): string
    {
        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
