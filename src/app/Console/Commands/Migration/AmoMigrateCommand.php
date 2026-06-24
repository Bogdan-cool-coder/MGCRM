<?php

declare(strict_types=1);

namespace App\Console\Commands\Migration;

use App\Domain\Migration\Extractors\AbstractExtractor;
use App\Domain\Migration\Extractors\CompanyExtractor;
use App\Domain\Migration\Extractors\ContactExtractor;
use App\Domain\Migration\Extractors\EventExtractor;
use App\Domain\Migration\Extractors\LeadExtractor;
use App\Domain\Migration\Extractors\NoteExtractor;
use App\Domain\Migration\Extractors\TaskExtractor;
use App\Domain\Migration\Loaders\MigrationLoader;
use App\Domain\Migration\Loaders\MigrationVerifier;
use App\Domain\Migration\Loaders\RollbackLoader;
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Migration\Services\AmoClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan amo:migrate {phase=extract} {--only=} {--limit=N} {--resume} {--dry-run}
 *
 * One-off AMO → MGCRM migration runner (Domain/Migration, dropped at M12). Five
 * decoupled phases:
 *   extract    pull AMO API v4 → JSONL staging (Slice A)
 *   transform  dry-run the load off staging → coverage report, writes nothing
 *   load       idempotent upsert of staging into MGCRM (Slice B)
 *   verify     parity (staging vs external_refs) + a few deal spot-checks
 *   rollback   undo the load via external_refs (reverse FK order) — --dry-run previews
 *
 * Run as a one-off container so the import never competes with the live prod
 * queue worker:
 *   docker compose run --rm app php artisan amo:migrate extract
 *   docker compose run --rm app php artisan amo:migrate transform
 *   docker compose run --rm app php artisan amo:migrate load --limit=50
 *   docker compose run --rm app php artisan amo:migrate verify
 *   docker compose run --rm app php artisan amo:migrate rollback --dry-run
 *
 * Flags:
 *   --only=leads,contacts   (extract) run a subset of extractors, in order
 *   --status=142            (extract) restrict the lead pull to AMO status ids
 *                           (CSV; 142 = won), expanded across both pipelines
 *   --limit=50              cap records (smoke / sample runs)
 *   --resume                (extract) reuse checkpoints + append
 *   --dry-run               (load) transform + count without writing;
 *                           (rollback) preview the delete counts without writing
 *
 * Extraction order respects dependencies: leads first (collects contact/company/
 * lead id sidecars), then contacts/companies (id batches), then tasks/events/
 * notes (per-lead). --only honours that order regardless of csv order.
 */
class AmoMigrateCommand extends Command
{
    protected $signature = 'amo:migrate
        {phase=extract : Migration phase (extract|transform|load|verify|rollback)}
        {--only= : CSV subset of extractors (leads,contacts,companies,tasks,events,notes)}
        {--status= : (extract) CSV of AMO status ids to restrict the lead pull to (e.g. 142 = won)}
        {--limit= : Cap records (smoke/sample runs)}
        {--resume : (extract) Reuse checkpoints and append instead of truncating}
        {--dry-run : (load) Transform + count without writing; (rollback) preview delete counts}';

    protected $description = 'AMO → MGCRM migration runner (extract|transform|load|verify|rollback)';

    /** Canonical extraction order (dependency-driven). */
    private const ORDER = ['leads', 'contacts', 'companies', 'tasks', 'events', 'notes'];

    public function handle(AmoClient $client): int
    {
        $phase = (string) $this->argument('phase');

        return match ($phase) {
            'extract' => $this->runExtract($client),
            'transform' => $this->runLoad(dryRun: true),
            'load' => $this->runLoad(dryRun: (bool) $this->option('dry-run')),
            'verify' => $this->runVerify(),
            'rollback' => $this->runRollback(dryRun: (bool) $this->option('dry-run')),
            default => $this->failUnknownPhase($phase),
        };
    }

    private function failUnknownPhase(string $phase): int
    {
        $this->error("Unknown phase '{$phase}' (expected: extract|transform|load|verify|rollback).");

        return self::FAILURE;
    }

    private function runExtract(AmoClient $client): int
    {
        if (config('amo_migration.api.token') === null || config('amo_migration.api.token') === '') {
            $this->error('AMO_MIGRATION_TOKEN is not set — refusing to run extract without a token.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $resume = (bool) $this->option('resume');
        $statuses = $this->resolveStatuses();
        $selected = $this->resolveSelection();

        $this->info(sprintf(
            'AMO extract: %s%s%s%s',
            implode(', ', $selected),
            $statuses !== [] ? ' (status='.implode(',', $statuses).')' : '',
            $limit !== null ? " (limit={$limit})" : '',
            $resume ? ' (resume)' : '',
        ));

        $extractors = $this->buildExtractors($client);
        $startedAt = microtime(true);
        $totals = [];

        foreach ($selected as $name) {
            $extractor = $extractors[$name];
            $extractor->withProgress(fn (string $msg) => $this->line('  '.$msg))
                ->withLimit($limit)
                ->withResume($resume)
                ->withStatuses($statuses);

            $this->line("→ {$name}");

            try {
                $totals[$name] = $extractor->run();
            } catch (Throwable $e) {
                $this->error("Extractor '{$name}' failed: ".$e->getMessage());
                $this->warn('Re-run with --resume to continue from the last checkpoint.');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Extract complete in '.round(microtime(true) - $startedAt, 1).'s:');

        foreach ($totals as $name => $written) {
            $this->line(sprintf('  %-10s %d records', $name, $written));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveSelection(): array
    {
        $only = $this->option('only');

        if ($only === null || trim((string) $only) === '') {
            return self::ORDER;
        }

        $requested = array_map('trim', explode(',', (string) $only));
        $unknown = array_diff($requested, self::ORDER);

        if ($unknown !== []) {
            $this->warn('Ignoring unknown extractor(s): '.implode(', ', $unknown));
        }

        // Keep canonical order regardless of how --only was ordered.
        return array_values(array_filter(self::ORDER, static fn (string $n): bool => in_array($n, $requested, true)));
    }

    /**
     * Parse --status (CSV of AMO status ids) into a deduped list of positive
     * ints. Empty / absent → [] (full archive, every status). Applied to the
     * lead pull only; other extractors ignore it.
     *
     * @return list<int>
     */
    private function resolveStatuses(): array
    {
        $raw = $this->option('status');

        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $ids = array_map('trim', explode(',', (string) $raw));

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @return array<string, AbstractExtractor>
     */
    private function buildExtractors(AmoClient $client): array
    {
        return [
            'leads' => new LeadExtractor($client),
            'contacts' => new ContactExtractor($client),
            'companies' => new CompanyExtractor($client),
            'tasks' => new TaskExtractor($client),
            'events' => new EventExtractor($client),
            'notes' => new NoteExtractor($client),
        ];
    }

    /**
     * TRANSFORM / LOAD. transform = a forced dry-run (coverage report, no writes);
     * load = the real idempotent import (or a dry-run with --dry-run).
     */
    private function runLoad(bool $dryRun): int
    {
        $reader = StagingReader::fromConfig();

        if (! $reader->exists('leads')) {
            $this->error('No leads.jsonl in staging — run `amo:migrate extract` first.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $loader = MigrationLoader::make($reader);

        $this->info(sprintf(
            'AMO %s%s%s',
            $dryRun ? 'transform (dry-run)' : 'load',
            $limit !== null ? " (limit={$limit})" : '',
            $dryRun ? ' — writing nothing' : '',
        ));

        $startedAt = microtime(true);

        $result = $loader->load([
            'dry_run' => $dryRun,
            'limit' => $limit,
            'progress' => fn (string $msg) => $this->line('  '.$msg),
        ]);

        $this->renderCoverageReport($dryRun, $result, microtime(true) - $startedAt);

        return self::SUCCESS;
    }

    /**
     * Collect-and-report coverage summary. ONE pass shows everything: what WOULD be
     * created, what was SKIPPED (and why), and every unmapped reference grouped
     * with an occurrence count — so an operator can patch config in one go rather
     * than re-running until the next crash.
     *
     * @param  array{stats: array<string, int>, conflicts: list<array<string, mixed>>, unmapped: array<string, array<string, int>>, dry_run: bool}  $result
     */
    private function renderCoverageReport(bool $dryRun, array $result, float $elapsed): void
    {
        $stats = $result['stats'];
        $verb = $dryRun ? 'WOULD create' : 'Created';

        $this->newLine();
        $this->info(($dryRun ? 'Transform (dry-run)' : 'Load').' complete in '.round($elapsed, 1).'s'.($dryRun ? ' — nothing written' : '').'.');

        // ----- What would be / was created -----
        $this->newLine();
        $this->line("<options=bold>{$verb}:</>");
        foreach ([
            'deals_created' => 'deals',
            'companies_created' => 'companies (real)',
            'companies_synthetic' => 'companies (synthetic)',
            'contacts_created' => 'contacts',
            'deal_contacts' => 'deal_contacts',
            'stage_history' => 'stage-history rows',
            'audits' => 'audit rows',
            'activities' => 'activities (tasks+notes)',
            'entity_logs' => 'entity-log rows',
            'deal_products' => 'deal product lines',
            'primary_deals' => 'primary/unique-client deals',
        ] as $key => $label) {
            $count = $key === 'activities'
                ? (($stats['tasks_created'] ?? 0) + ($stats['notes_created'] ?? 0))
                : ($stats[$key] ?? 0);
            $this->line(sprintf('  %-30s %d', $label, $count));
        }

        // ----- What was skipped (and why) -----
        $skippedDeals = $stats['unmapped_deals'] ?? 0;
        $failedDeals = $stats['failed_deals'] ?? 0;
        $skippedHistory = $stats['history_skipped'] ?? 0;
        $skippedNotes = $stats['notes_skipped'] ?? 0;
        $skippedActs = $stats['activities_skipped'] ?? 0;
        $skippedProducts = $stats['products_skipped'] ?? 0;
        $unmappedProducts = $stats['products_unmapped'] ?? 0;

        if ($skippedDeals + $failedDeals + $skippedHistory + $skippedNotes + $skippedActs + $skippedProducts + $unmappedProducts > 0) {
            $this->newLine();
            $this->warn('SKIPPED:');
            if ($skippedDeals > 0) {
                $this->line(sprintf('  %-30s %d', 'deals (unresolvable stage/etc)', $skippedDeals).$this->breakdown($stats, 'skipped_deal:'));
            }
            if ($failedDeals > 0) {
                $this->line(sprintf('  %-30s %d', 'deals (unexpected error)', $failedDeals));
            }
            if ($skippedHistory > 0) {
                $this->line(sprintf('  %-30s %d', 'timeline rows', $skippedHistory).$this->breakdown($stats, 'skipped_history:'));
            }
            if ($skippedActs > 0) {
                $this->line(sprintf('  %-30s %d', 'activities (malformed)', $skippedActs));
            }
            if ($skippedNotes > 0) {
                $this->line(sprintf('  %-30s %d', 'notes (mapped to skip)', $skippedNotes));
            }
            if ($skippedProducts > 0) {
                $this->line(sprintf('  %-30s %d', 'products (mapped to skip)', $skippedProducts));
            }
            if ($unmappedProducts > 0) {
                $this->line(sprintf('  %-30s %d', 'products (no curation row)', $unmappedProducts));
            }
        }

        // ----- Unmapped references (grouped, count-sorted) -----
        $unmapped = $result['unmapped'] ?? [];
        $hasUnmapped = false;
        foreach ($unmapped as $ids) {
            if ($ids !== []) {
                $hasUnmapped = true;
                break;
            }
        }

        if ($hasUnmapped) {
            $this->newLine();
            $this->warn('UNMAPPED references (add to config/amo_migration.php):');
            foreach ($unmapped as $bucket => $ids) {
                if ($ids === []) {
                    continue;
                }
                $pairs = [];
                foreach ($ids as $id => $count) {
                    $pairs[] = "{$id}×{$count}";
                }
                $this->line(sprintf('  %-12s %s', $bucket.':', implode(', ', $pairs)));
            }
        }

        // ----- Raw conflict tail (operator drill-down) -----
        $conflicts = $result['conflicts'];
        if ($conflicts !== []) {
            $this->newLine();
            $this->warn(count($conflicts).' conflict(s) total — first 20:');
            foreach (array_slice($conflicts, 0, 20) as $conflict) {
                $this->line('  '.json_encode($conflict, JSON_UNESCAPED_UNICODE));
            }
        } else {
            $this->newLine();
            $this->info('No conflicts — full coverage.');
        }
    }

    /**
     * Render a " (reason=n, reason2=m)" breakdown from prefixed stat keys.
     *
     * @param  array<string, int>  $stats
     */
    private function breakdown(array $stats, string $prefix): string
    {
        $parts = [];
        foreach ($stats as $key => $count) {
            if ($count > 0 && str_starts_with($key, $prefix)) {
                $parts[] = substr($key, strlen($prefix)).'='.$count;
            }
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private function runVerify(): int
    {
        $verifier = new MigrationVerifier(StagingReader::fromConfig());

        $report = $verifier->verify();

        $this->info('AMO migration parity (staging vs loaded):');
        foreach ($report['parity'] as $entity => $row) {
            $flag = $row['diff'] === 0 ? 'OK' : 'DIFF';
            $this->line(sprintf(
                '  %-14s staging=%-7d loaded=%-7d diff=%-6d [%s]',
                $entity,
                $row['staging'],
                $row['loaded'],
                $row['diff'],
                $flag,
            ));
        }

        if ($report['spot_checks'] !== []) {
            $this->newLine();
            $this->info('Spot-check (latest deals):');
            foreach ($report['spot_checks'] as $deal) {
                $this->line('  '.json_encode($deal, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }

    /**
     * ROLLBACK — undo the load via external_refs (reverse FK order). --dry-run
     * previews the delete counts without writing. The real delete is all-or-nothing
     * (one transaction): it either removes every imported deal/contact/company or
     * leaves the database untouched.
     */
    private function runRollback(bool $dryRun): int
    {
        if (! $dryRun && ! $this->confirmRollback()) {
            $this->warn('Rollback aborted.');

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'AMO rollback (dry-run) — writing nothing' : 'AMO rollback — deleting imported entities');

        $startedAt = microtime(true);

        $counts = (new RollbackLoader)->rollback([
            'dry_run' => $dryRun,
            'progress' => fn (string $msg) => $this->line('  '.$msg),
        ]);

        $verb = $dryRun ? 'WOULD delete' : 'Deleted';

        $this->newLine();
        $this->info(($dryRun ? 'Rollback (dry-run)' : 'Rollback').' complete in '.round(microtime(true) - $startedAt, 1).'s'.($dryRun ? ' — nothing written' : '').'.');
        $this->newLine();
        $this->line("<options=bold>{$verb}:</>");
        foreach ([
            'deals' => 'deals (+ contacts/history/audits/products cascade)',
            'contacts' => 'contacts (+ channels/links cascade)',
            'companies' => 'companies (+ requisites/channels cascade)',
            'activities' => 'activities (tasks+notes)',
            'entity_logs' => 'entity-log rows',
            'external_refs' => 'external_refs (provenance)',
        ] as $key => $label) {
            $this->line(sprintf('  %-50s %d', $label, $counts[$key] ?? 0));
        }

        return self::SUCCESS;
    }

    private function confirmRollback(): bool
    {
        // Non-interactive runs (CI / scripted) must pass --dry-run first; a real
        // destructive rollback requires an interactive confirmation.
        if (! $this->input->isInteractive()) {
            $this->error('Refusing a non-interactive destructive rollback — run with --dry-run to preview, or run interactively to confirm.');

            return false;
        }

        return $this->confirm('This permanently deletes every AMO-imported deal/contact/company. Continue?', false);
    }
}
