<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FindDeal extends Command
{
    protected $signature = 'macrodata:find-deal
        {--company= : Company ID to use (from companies table)}
        {--host= : MySQL host (if no company)}
        {--port=3306 : MySQL port}
        {--database= : MySQL database}
        {--user= : MySQL user}
        {--password= : MySQL password}
        {--deal= : Deal number to search}
        {--date= : Deal date (Y-m-d)}';

    protected $description = 'Find a deal in MacroData by contract number and date';

    public function handle(ConnectionService $connectionService): int
    {
        $companyId = $this->option('company');

        if ($companyId) {
            $company = Company::find($companyId);
            if (!$company) {
                $this->error("Company ID {$companyId} not found");
                return 1;
            }
            $this->info("Using company: {$company->name}");
        } else {
            $host = $this->option('host');
            $database = $this->option('database');
            if (!$host || !$database) {
                $this->error('Provide --company=ID or --host/--database/--user/--password');
                return 1;
            }
            $company = new Company([
                'macrodata_host' => $host,
                'macrodata_port' => $this->option('port'),
                'macrodata_database' => $database,
                'macrodata_username' => $this->option('user'),
                'macrodata_password' => $this->option('password'),
            ]);
        }

        try {
            $connectionService->connect($company);
            DB::connection('macrodata')->getPdo();
            $this->info("Connected to MacroData [{$company->macrodata_database}@{$company->macrodata_host}]");
        } catch (\Exception $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return 1;
        }

        $dealNum = $this->option('deal');
        $date = $this->option('date');

        // Show columns
        $columns = DB::connection('macrodata')->select("SHOW COLUMNS FROM estate_deals");
        $this->info("\n=== estate_deals columns ===");
        foreach ($columns as $col) {
            $this->line("  {$col->Field} ({$col->Type})");
        }

        // Search by deal number
        if ($dealNum) {
            $this->info("\n=== Searching for '{$dealNum}' ===");
            $textCols = array_filter($columns, fn($c) =>
                str_contains($c->Type, 'char') ||
                str_contains($c->Type, 'text') ||
                str_contains($c->Type, 'varchar')
            );

            foreach ($textCols as $col) {
                $field = $col->Field;
                try {
                    $results = DB::connection('macrodata')
                        ->table('estate_deals')
                        ->where($field, 'LIKE', "%{$dealNum}%")
                        ->limit(5)
                        ->get();

                    if ($results->isNotEmpty()) {
                        $this->warn("FOUND in `{$field}`:");
                        foreach ($results as $row) {
                            $this->line("  deal_id={$row->deal_id}, {$field}=" . $row->$field);
                        }
                        $this->info("\n--- Full record ---");
                        foreach ((array) $results->first() as $k => $v) {
                            if ($v !== null) {
                                $this->line("  {$k} = {$v}");
                            }
                        }
                        return 0;
                    }
                } catch (\Exception $e) {
                    // skip
                }
            }
            $this->warn("Not found by '{$dealNum}'");
        }

        // Search by date
        if ($date) {
            $this->info("\n=== Deals on {$date} ===");
            $deals = DB::connection('macrodata')
                ->table('estate_deals')
                ->whereDate('deal_date', $date)
                ->get();

            $this->info("Found {$deals->count()} deals");

            if ($deals->isNotEmpty()) {
                if ($dealNum) {
                    foreach ($deals as $deal) {
                        foreach ((array) $deal as $key => $val) {
                            if (is_string($val) && (str_contains($val, $dealNum) || str_contains($val, 'GHP'))) {
                                $this->warn("MATCH: deal_id={$deal->deal_id}, `{$key}` = {$val}");
                                foreach ((array) $deal as $k => $v) {
                                    if ($v !== null) {
                                        $this->line("  {$k} = {$v}");
                                    }
                                }
                                return 0;
                            }
                        }
                    }
                }

                if ($deals->count() <= 50) {
                    foreach ($deals as $deal) {
                        $parts = ["deal_id={$deal->deal_id}"];
                        foreach ((array) $deal as $k => $v) {
                            if ($v !== null && $k !== 'deal_id') {
                                $parts[] = "{$k}={$v}";
                            }
                        }
                        $this->line('  ' . implode(' | ', $parts));
                    }
                }
            }
        }

        return 0;
    }
}
