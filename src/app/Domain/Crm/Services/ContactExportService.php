<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ContactExportService — XLSX export of contacts.
 * Pattern: 1-in-1 with DealExportService (PhpSpreadsheet v5, CHUNK, chunkById).
 *
 * Columns: ID / Full Name / Phone / Email / Position / Company (primary) /
 *          Owner / Status / Tags / Last Activity / Created At
 */
class ContactExportService
{
    private const CHUNK = 500;

    private const HEADERS = [
        'ID',
        'Full Name',
        'Phone',
        'Email',
        'Position',
        'Company (primary)',
        'Owner',
        'Status',
        'Tags',
        'Last Activity',
        'Created At',
    ];

    /**
     * Build XLSX bytes for the given contact IDs.
     * If $contactIds is empty, exports all (visibility NOT enforced here — caller
     * pre-filters by authorized IDs from BulkContactService).
     *
     * @param  list<int>  $contactIds
     */
    public function buildXlsx(array $contactIds): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');

        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValue($this->addr($col + 1, 1), $header);
        }

        $row = 2;

        Contact::query()
            ->when($contactIds !== [], static fn ($q) => $q->whereIn('id', $contactIds))
            ->with(['owner', 'companyLinks' => static fn ($q) => $q->where('is_primary', true)->with('company')])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($contacts) use ($sheet, &$row): void {
                foreach ($contacts as $contact) {
                    $this->writeRow($sheet, $contact, $row);
                    $row++;
                }
            });

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function writeRow(Worksheet $sheet, Contact $contact, int $row): void
    {
        $primaryLink = $contact->companyLinks->first();

        $values = [
            $contact->id,
            $contact->full_name,
            $contact->phone,
            $contact->email,
            $contact->position,
            $primaryLink?->company?->name,
            $contact->owner?->full_name,
            $contact->status?->value,
            implode(', ', $contact->tags ?? []),
            $contact->last_activity_at?->toDateTimeString(),
            $contact->created_at?->toDateTimeString(),
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
