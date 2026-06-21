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
use App\Domain\Migration\Loaders\StagingReader;
use App\Domain\Migration\Services\AmoClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan amo:migrate {phase=extract} {--only=} {--limit=N} {--resume} {--dry-run}
 *
 * One-off AMO → MGCRM migration runner (Domain/Migration, dropped at M12). Four
 * decoupled phases:
 *   extract    pull AMO API v4 → JSONL staging (Slice A)
 *   transform  dry-run the load off staging → coverage report, writes nothing
 *   load       idempotent upsert of staging into MGCRM (Slice B)
 *   verify     parity (staging vs external_refs) + a few deal spot-checks
 *
 * Run as a one-off container so the import never competes with the live prod
 * queue worker:
 *   docker compose run --rm app php artisan amo:migrate extract
 *   docker compose run --rm app php artisan amo:migrate transform
 *   docker compose run --rm app php artisan amo:migrate load --limit=50
 *   docker compose run --rm app php artisan amo:migrate verify
 *
 * Flags:
 *   --only=leads,contacts   (extract) run a subset of extractors, in order
 *   --limit=50              cap records (smoke / sample runs)
 *   --resume                (extract) reuse checkpoints + append
 *   --dry-run               (load) transform + count without writing
 *
 * Extraction order respects dependencies: leads first (collects contact/company/
 * lead id sidecars), then contacts/companies (id batches), then tasks/events/
 * notes (per-lead). --only honours that order regardless of csv order.
 */
class AmoMigrateCommand extends Command
{
    protected $signature = 'amo:migrate
        {phase=extract : Migration phase (extract|transform|load|verify)}
        {--only= : CSV subset of extractors (leads,contacts,companies,tasks,events,notes)}
        {--limit= : Cap records (smoke/sample runs)}
        {--resume : (extract) Reuse checkpoints and append instead of truncating}
        {--dry-run : (load) Transform + count without writing}';

    protected $description = 'AMO → MGCRM migration runner (extract|transform|load|verify)';

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
            default => $this->failUnknownPhase($phase),
        };
    }

    private function failUnknownPhase(string $phase): int
    {
        $this->error("Unknown phase '{$phase}' (expected: extract|transform|load|verify).");

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
        $selected = $this->resolveSelection();

        $this->info(sprintf(
            'AMO extract: %s%s%s',
            implode(', ', $selected),
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
                ->withResume($resume);

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

        $this->newLine();
        $this->info(($dryRun ? 'Transform' : 'Load').' complete in '.round(microtime(true) - $startedAt, 1).'s:');

        foreach ($result['stats'] as $key => $count) {
            $this->line(sprintf('  %-22s %d', $key, $count));
        }

        $conflicts = $result['conflicts'];
        if ($conflicts !== []) {
            $this->newLine();
            $this->warn(count($conflicts).' conflict(s) needing review:');
            foreach (array_slice($conflicts, 0, 20) as $conflict) {
                $this->line('  '.json_encode($conflict, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
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
}
