<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
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

    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

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
     * Build XLSX bytes for the given contact IDs, scoped to what $actor may see.
     *
     * When $contactIds is non-empty, the export is restricted to that subset
     * intersected with the actor's visibility scope (selected-rows export).
     * When $contactIds is empty, exports the actor's entire visible set — never
     * the full table regardless of who calls this method.
     *
     * @param  list<int>  $contactIds
     */
    public function buildXlsx(array $contactIds, User $actor): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');

        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValue($this->addr($col + 1, 1), $header);
        }

        $row = 2;

        // Apply row-level visibility scope first — the actor can never export
        // contacts they cannot list. Then narrow to the requested id subset.
        $this->visibility->applyScope(
            Contact::query(),
            $actor,
            ['owner_id'],
        )
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
