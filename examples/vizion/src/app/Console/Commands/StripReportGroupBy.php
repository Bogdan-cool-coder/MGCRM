<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;

/**
 * One-off + idempotent maintenance command.
 *
 * Removes the deprecated `config.group_by` key (and any leftover
 * `metadata.grouped` flag) from every report. The grouped/drill-down report
 * view has been retired from the product; this command guarantees that custom
 * (AI-generated) reports — which are NOT recreated by any seeder and therefore
 * survive reseeds — are flattened too.
 *
 * Safe to run repeatedly: reports that already have no group_by are skipped.
 */
class StripReportGroupBy extends Command
{
    protected $signature = 'reports:strip-group-by {--dry-run : List affected reports without saving}';

    protected $description = 'Remove deprecated config.group_by (and metadata.grouped) from all reports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $affected = 0;

        Report::query()->chunkById(100, function ($reports) use (&$affected, $dryRun): void {
            foreach ($reports as $report) {
                $config = $report->config ?? [];
                $metadata = $report->metadata ?? [];

                $hadGroupBy = is_array($config) && array_key_exists('group_by', $config);
                $hadGroupedFlag = is_array($metadata) && array_key_exists('grouped', $metadata);

                if (! $hadGroupBy && ! $hadGroupedFlag) {
                    continue;
                }

                $affected++;
                $title = $report->getTranslation('title', 'ru', false) ?: ('#' . $report->id);
                $this->line(sprintf('  • report #%d "%s"%s%s', $report->id, $title,
                    $hadGroupBy ? ' [group_by]' : '',
                    $hadGroupedFlag ? ' [metadata.grouped]' : ''
                ));

                if ($dryRun) {
                    continue;
                }

                if ($hadGroupBy) {
                    unset($config['group_by']);
                    $report->config = $config;
                }

                if ($hadGroupedFlag) {
                    unset($metadata['grouped']);
                    $report->metadata = $metadata;
                }

                $report->save();
            }
        });

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d report(s) would be stripped of group_by.', $affected));
        } else {
            $this->info(sprintf('Done: %d report(s) stripped of group_by.', $affected));
        }

        return self::SUCCESS;
    }
}
