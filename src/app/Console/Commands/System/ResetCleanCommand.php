<?php

declare(strict_types=1);

namespace App\Console\Commands\System;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * php artisan app:reset-clean [--force]
 *
 * "Сброс настроек" — drops every table (migrate:fresh) and re-seeds ONLY the
 * baseline configuration (DatabaseSeeder::baselineSeeders(): roles/permissions,
 * accounts, product catalog, sales pipeline + stages, lost reasons,
 * meeting-report registry, licensor entities, contract templates + variables,
 * default approval route, message templates).
 *
 * Business data (deals, contacts, deal products, activities, documents/revisions,
 * automations/runs, onboarding content/progress, notifications, KPI) is NOT
 * seeded — the system is returned to a clean, configured-but-empty baseline.
 *
 * Destructive: drops all tables. The HTTP entrypoint (SystemResetController) adds
 * the guards (admin-only + config('system.reset_enabled')); this command is the
 * raw mechanism and requires --force in non-interactive contexts.
 */
class ResetCleanCommand extends Command
{
    protected $signature = 'app:reset-clean {--force : Required to run without an interactive confirmation}';

    protected $description = 'Drop all tables and re-seed ONLY the baseline configuration (clean reset)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! $this->confirm('This DROPS ALL TABLES and re-seeds baseline config only. Continue?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->info('Dropping all tables (migrate:fresh)...');
        Artisan::call('migrate:fresh', ['--force' => true], $this->getOutput());

        $this->info('Seeding baseline configuration...');
        foreach (DatabaseSeeder::baselineSeeders() as $seederClass) {
            $this->line("  - {$seederClass}");
            Artisan::call('db:seed', [
                '--class' => $seederClass,
                '--force' => true,
            ], $this->getOutput());
        }

        $this->info('Clean reset complete: baseline configuration restored, business data cleared.');

        return self::SUCCESS;
    }
}
