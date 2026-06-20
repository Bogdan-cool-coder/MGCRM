<?php

declare(strict_types=1);

namespace App\Console\Commands\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * php artisan sales:backfill-unique-clients [--dry-run]
 *
 * One-shot maintenance command (N5/Фича 3): retro-stamps the client-lifecycle
 * state that the live DealMoveService detect now maintains going forward.
 *
 * For every company that has at least one WON deal:
 *   1. Pick the EARLIEST won deal — ordered by COALESCE(signed_at, closed_at)
 *      ascending, then by id ascending on ties. That deal is the "primary"
 *      (the one that made the company a unique client).
 *   2. Stamp is_primary_deal = true on it, false on every other won deal of the
 *      same company (so a re-run that finds a new earlier deal corrects itself).
 *   3. Mark the company a unique client via CompanyService::markAsUniqueClient
 *      with that earliest date (idempotent: a no-op when already converted, so
 *      the date is never moved on a second run).
 *
 * Idempotent: re-running yields the same flags and the same unique_client_since.
 * --dry-run prints what would change without writing.
 */
class BackfillUniqueClientsCommand extends Command
{
    protected $signature = 'sales:backfill-unique-clients {--dry-run : Report changes without writing}';

    protected $description = 'Retro-stamp is_primary_deal + company unique_client_since from existing won deals';

    public function handle(CompanyService $companies): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Company ids that have at least one won deal, deterministically ordered.
        $companyIds = Deal::query()
            ->whereNotNull('company_id')
            ->whereHas('stage', static fn (Builder $q) => $q->where('is_won', true))
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($companyIds === []) {
            $this->info('No companies with won deals — nothing to backfill.');

            return self::SUCCESS;
        }

        $marked = 0;

        foreach ($companyIds as $companyId) {
            $marked += $this->backfillCompany($companyId, $companies, $dryRun) ? 1 : 0;
        }

        $verb = $dryRun ? 'Would backfill' : 'Backfilled';
        $this->info("{$verb} {$marked} of ".count($companyIds).' company(ies) with won deals.');

        return self::SUCCESS;
    }

    /**
     * Backfill a single company. Returns true when its earliest won deal was
     * (re)stamped as primary. Each company is processed in its own transaction so
     * a failure on one does not roll back the others.
     */
    private function backfillCompany(int $companyId, CompanyService $companies, bool $dryRun): bool
    {
        // Won deals of this company, earliest first: COALESCE(signed_at, closed_at)
        // ascending, id ascending on ties. SoftDeletes global scope already
        // excludes deleted deals. orderByRaw is portable across SQLite & Postgres.
        $wonDeals = Deal::query()
            ->where('company_id', $companyId)
            ->whereHas('stage', static fn (Builder $q) => $q->where('is_won', true))
            ->orderByRaw('COALESCE(signed_at, closed_at) asc')
            ->orderBy('id')
            ->get();

        if ($wonDeals->isEmpty()) {
            return false;
        }

        /** @var Deal $primary */
        $primary = $wonDeals->first();
        $primaryDate = ($primary->signed_at ?? $primary->closed_at ?? now())->copy()->startOfDay();

        if ($dryRun) {
            $this->line(
                "company #{$companyId}: primary deal #{$primary->id} ".
                "(date {$primaryDate->toDateString()}), ".
                ($wonDeals->count() - 1).' upsell(s)'
            );

            return true;
        }

        DB::transaction(function () use ($wonDeals, $primary, $companyId, $primaryDate, $companies): void {
            foreach ($wonDeals as $deal) {
                $shouldBePrimary = (int) $deal->id === (int) $primary->id;

                // Only write when the flag actually flips — keeps the command
                // cheap and the audit trail clean on a re-run.
                if ((bool) $deal->is_primary_deal !== $shouldBePrimary) {
                    $deal->update(['is_primary_deal' => $shouldBePrimary]);
                }
            }

            $company = Company::find($companyId);
            if ($company !== null) {
                // Idempotent: a no-op if already converted (date never moves).
                $companies->markAsUniqueClient($company, $primaryDate, null);
            }
        });

        return true;
    }
}
