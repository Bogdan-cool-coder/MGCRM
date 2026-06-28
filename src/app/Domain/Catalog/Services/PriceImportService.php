<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Data\ImportResult;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * PriceImportService — Excel price-list import with idempotent upsert.
 *
 * PLAN §Д: per-row errors, no full-file rollback, dry-run mode.
 * Amounts in Excel are in full currency units (e.g. 1500.00 KZT).
 * In DB they are stored as integer kopecks (×100). ARCHITECTURE.md §3.
 */
class PriceImportService
{
    /** @var list<string> */
    private static array $requiredColumns = ['code', 'name', 'currency_code', 'amount'];

    public function __construct(
        private readonly ProductGroupService $groupService,
    ) {}

    /**
     * Import prices from an uploaded Excel file.
     * Partial writes: valid rows are committed even when later rows error.
     */
    public function importFromExcel(UploadedFile $file, bool $dryRun = false): ImportResult
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        $headers = $this->extractHeaders($sheet);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED']);

        foreach ($sheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            $rowData = $this->extractRowData($row, $headers);

            // Skip completely empty rows.
            if ($this->isEmptyRow($rowData)) {
                continue;
            }

            // Validate required columns.
            $validationError = $this->validateRow($rowData, $rowIndex, $supported);
            if ($validationError !== null) {
                $errors[] = $validationError;
                $skipped++;

                continue;
            }

            if ($dryRun) {
                // Determine would_insert / would_update without writing.
                $exists = Product::where('code', trim((string) $rowData['code']))->exists();
                if ($exists) {
                    $updated++;
                } else {
                    $inserted++;
                }

                continue;
            }

            // Real write.
            try {
                $result = $this->processRow($rowData, $supported);
                if ($result === 'inserted') {
                    $inserted++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowIndex, 'message' => $e->getMessage()];
                $skipped++;
            }
        }

        return new ImportResult(
            inserted: $inserted,
            updated: $updated,
            skipped: $skipped,
            errors: $errors,
            dryRun: $dryRun,
        );
    }

    /**
     * Process a single row: upsert product, plan, price.
     * Returns 'inserted' or 'updated'.
     */
    private function processRow(array $rowData, array $supported): string
    {
        $code = trim((string) $rowData['code']);
        $name = trim((string) $rowData['name']);
        $currencyCode = strtoupper(trim((string) $rowData['currency_code']));
        $amountRaw = (float) $rowData['amount'];
        $amountKopecks = (int) round($amountRaw * 100); // convert to kopecks

        // Resolve group.
        $groupId = null;
        if (! empty($rowData['group'])) {
            $groupName = trim((string) $rowData['group']);
            $group = ProductGroup::firstOrCreate(
                ['name' => $groupName],
                ['is_active' => true, 'sort_order' => 0],
            );
            $groupId = $group->id;
        }

        // Upsert product.
        $product = Product::where('code', $code)->first();
        $isNew = $product === null;

        if ($isNew) {
            $product = Product::create([
                'code' => $code,
                'name' => $name,
                'description' => trim((string) ($rowData['description'] ?? '')),
                'group_id' => $groupId,
                'pricing_type' => ! empty($rowData['pricing_type']) ? trim((string) $rowData['pricing_type']) : 'fixed',
                'is_active' => $this->parseBool($rowData['is_active'] ?? true),
                'sort_order' => isset($rowData['sort_order']) ? (int) $rowData['sort_order'] : 0,
            ]);
        } else {
            $product->update([
                'name' => $name,
                'description' => trim((string) ($rowData['description'] ?? $product->description)),
                'group_id' => $groupId ?? $product->group_id,
                'pricing_type' => ! empty($rowData['pricing_type']) ? trim((string) $rowData['pricing_type']) : $product->pricing_type?->value ?? 'fixed',
                'is_active' => $this->parseBool($rowData['is_active'] ?? $product->is_active),
                'sort_order' => isset($rowData['sort_order']) ? (int) $rowData['sort_order'] : $product->sort_order,
            ]);
        }

        // Resolve plan (optional).
        $planId = null;
        if (! empty($rowData['plan_name'])) {
            $planName = trim((string) $rowData['plan_name']);
            $planCode = ! empty($rowData['plan_code']) ? trim((string) $rowData['plan_code']) : null;
            $planUnit = ! empty($rowData['plan_unit']) ? trim((string) $rowData['plan_unit']) : 'year';

            $planQuery = $product->plans()->where('name', $planName);
            if ($planCode !== null) {
                $planQuery = $product->plans()->where('code', $planCode);
            }

            $plan = $planQuery->first();
            if ($plan === null) {
                $plan = $product->plans()->create([
                    'code' => $planCode,
                    'name' => $planName,
                    'unit' => $planUnit,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            } else {
                $plan->update(['name' => $planName, 'unit' => $planUnit]);
            }

            $planId = $plan->id;
        }

        // Upsert price.
        ProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'plan_id' => $planId,
                'currency_code' => $currencyCode,
            ],
            ['amount' => $amountKopecks],
        );

        return $isNew ? 'inserted' : 'updated';
    }

    /**
     * Extract column headers from row 1.
     *
     * @return array<string, string> column name => column letter (A, B, ...)
     */
    private function extractHeaders(Worksheet $sheet): array
    {
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $cell = $sheet->getCell($colLetter.'1');
            $value = trim((string) $cell->getValue());
            if ($value !== '') {
                $headers[strtolower($value)] = $colLetter;
            }
        }

        return $headers;
    }

    /**
     * Extract a keyed row array using the header map.
     *
     * For the `amount` column we read the raw cell value (getValue()) instead of
     * the formatted display string (getFormattedValue()). The formatted value can
     * carry locale-specific thousands separators and currency symbols (e.g.
     * "1 500,00 KZT") that break (float) casting; the raw value is always the
     * bare numeric.  For all other columns the formatted value is preferred so
     * string cells (code, name, etc.) render as expected.
     *
     * @param  array<string, string>  $headers  column name => column letter
     * @return array<string, mixed>
     */
    private function extractRowData(Row $row, array $headers): array
    {
        $data = [];
        $rowIndex = $row->getRowIndex();
        $sheet = $row->getWorksheet();

        foreach ($headers as $name => $colLetter) {
            $cell = $sheet->getCell($colLetter.$rowIndex);

            if ($name === 'amount') {
                // Use the raw (unformatted) cell value for numeric accuracy.
                $raw = $cell->getValue();

                // Fallback: if the cell was stored as a formatted string (e.g.
                // exported from Excel with grouping), strip grouping separators and
                // normalise the decimal symbol so (float) cast produces the correct
                // value.
                if (is_string($raw) && $raw !== '') {
                    // Remove common grouping separators (space, comma when followed
                    // by 3 digits, apostrophe) and normalise comma→dot for decimal.
                    $raw = preg_replace('/[\s\x{00A0}]/u', '', $raw); // strip spaces/NBSP
                    $raw = preg_replace('/,(\d{3})/', '$1', $raw ?? ''); // remove grouping comma
                    $raw = str_replace(',', '.', $raw ?? ''); // comma decimal → dot
                    $raw = preg_replace('/[^\d.]/', '', $raw ?? ''); // strip currency symbols
                }

                $data[$name] = $raw;
            } else {
                $data[$name] = $cell->getFormattedValue();
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $rowData */
    private function isEmptyRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single row.
     *
     * @param  array<string, mixed>  $rowData
     * @return array{row: int, message: string}|null
     */
    private function validateRow(array $rowData, int $rowIndex, array $supported): ?array
    {
        foreach (self::$requiredColumns as $col) {
            if (empty($rowData[$col]) || trim((string) $rowData[$col]) === '') {
                return ['row' => $rowIndex, 'message' => "Missing required column '{$col}'."];
            }
        }

        $currency = strtoupper(trim((string) $rowData['currency_code']));
        if (! in_array($currency, $supported, true)) {
            return ['row' => $rowIndex, 'message' => "Unknown currency code '{$currency}'."];
        }

        $amount = (float) $rowData['amount'];
        if ($amount <= 0) {
            return ['row' => $rowIndex, 'message' => "Amount must be greater than 0, got '{$rowData['amount']}'."];
        }

        return null;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}
