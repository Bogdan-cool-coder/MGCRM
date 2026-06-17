<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\CertificateNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * CertificateNumberingService — atomically reserves and formats a certificate number.
 *
 * Format: CERT-{YYYY}-{N:04d}
 * Example: CERT-2026-0001, CERT-2026-0042
 *
 * Pattern: SELECT FOR UPDATE inside DB::transaction (same as ContractNumberingService S2.4).
 * Called ONLY from CertificateService::generate() — never from HTTP directly.
 *
 * Numbers are monotonically increasing per year. A rollback at the generation
 * layer does NOT un-reserve the number (gap in sequence is acceptable — same
 * as S2.4 decision Q5). New year → new row, sequence resets to 1.
 */
class CertificateNumberingService
{
    /**
     * Reserve and return the next certificate number for the given year.
     *
     * @param  int  $year  4-digit year (e.g. 2026)
     * @return string Formatted number, e.g. "CERT-2026-0001"
     */
    public function nextNumber(int $year): string
    {
        return DB::transaction(function () use ($year): string {
            // SELECT FOR UPDATE — prevents concurrent double-increment.
            // In SQLite :memory: this is a no-op (single connection); in PG it
            // acquires a row-level lock. Tests verify sequential correctness.
            $seq = CertificateNumberSequence::query()
                ->lockForUpdate()
                ->where('year', $year)
                ->first();

            if ($seq === null) {
                // First certificate for this year.
                $seq = CertificateNumberSequence::create([
                    'year' => $year,
                    'current_number' => 1,
                ]);
            } else {
                $seq->increment('current_number');
                $seq->refresh();
            }

            return sprintf('CERT-%d-%04d', $year, $seq->current_number);
        });
    }
}
