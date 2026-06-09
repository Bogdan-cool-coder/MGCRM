<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckMacrodataModels extends Command
{
    protected $signature = 'macrodata:check-models {--company= : Company name or ID}';

    protected $description = 'Check that each MacroData table has a corresponding model with matching name (snake_case table → PascalCase model)';

    public function handle(): int
    {
        $companyQuery = $this->option('company');

        if ($companyQuery) {
            if (is_numeric($companyQuery)) {
                $company = Company::find($companyQuery);
            } else {
                $company = Company::where('name', 'like', "%{$companyQuery}%")->first();
            }
        } else {
            $company = Company::where('name', 'like', '%Capital%')->first();
        }

        if (!$company) {
            $this->error('Company not found');
            return 1;
        }

        $this->info("Connecting to MacroData for company: {$company->name}");

        $service = app(ConnectionService::class);
        $service->connect($company);

        // Step 1: Get all tables from MacroData DB
        $dbTables = DB::connection('macrodata')->select("SHOW TABLES");
        $dbKey = 'Tables_in_' . config('database.connections.macrodata.database');
        $tableNames = array_map(fn($t) => $t->$dbKey, $dbTables);
        sort($tableNames);

        // Step 2: Get all existing model files with their $table property
        $modelsPath = app_path('Models/MacroData');
        $modelFiles = glob($modelsPath . '/*.php');
        $existingModels = [];
        foreach ($modelFiles as $file) {
            $modelName = basename($file, '.php');
            $content = file_get_contents($file);
            if (preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"];/", $content, $matches)) {
                $existingModels[$modelName] = $matches[1];
            } else {
                $existingModels[$modelName] = null;
            }
        }

        $this->newLine();
        $this->info('=== Detailed Analysis ===');
        $this->newLine();

        // Categorize results
        $perfect = [];      // Model name matches table name exactly
        $rename = [];       // Model exists but wrong name (singular vs plural)
        $missing = [];      // No model at all
        $extra = [];        // Models without matching tables

        // Build lookup by $table property
        $modelByTable = [];
        foreach ($existingModels as $modelName => $table) {
            if ($table) {
                $modelByTable[$table] = $modelName;
            }
        }

        foreach ($tableNames as $table) {
            $expectedModel = $this->tableToModel($table);

            // Check 1: Perfect match (model name = expected)
            if (in_array($expectedModel, array_keys($existingModels))) {
                $perfect[] = [
                    'table' => $table,
                    'model' => $expectedModel,
                    'table_prop' => $existingModels[$expectedModel],
                ];
                continue;
            }

            // Check 2: Model exists with different name but points to this table
            if (isset($modelByTable[$table])) {
                $currentModel = $modelByTable[$table];
                $rename[] = [
                    'table' => $table,
                    'current_model' => $currentModel,
                    'expected_model' => $expectedModel,
                    'action' => "RENAME {$currentModel} → {$expectedModel}",
                ];
                continue;
            }

            // Check 3: No model at all
            $missing[] = [
                'table' => $table,
                'expected_model' => $expectedModel,
                'action' => "CREATE {$expectedModel}",
            ];
        }

        // Find extra models (not pointing to any table)
        $usedModels = [];
        foreach ($rename as $r) {
            $usedModels[] = $r['current_model'];
        }
        foreach ($perfect as $p) {
            $usedModels[] = $p['model'];
        }

        foreach ($existingModels as $modelName => $table) {
            if (!in_array($modelName, $usedModels)) {
                $extra[] = [
                    'model' => $modelName,
                    'table_prop' => $table,
                    'action' => $table ? "DELETE {$modelName} (orphan)" : "DELETE {$modelName} (no \$table)",
                ];
            }
        }

        // Output results

        // 1. Perfect matches
        $this->line("<fg=green>✓ PERFECT MATCH (" . count($perfect) . ") - no action needed:</>");
        foreach ($perfect as $item) {
            $this->line("  <fg=green>✓</> {$item['table']} → <fg=cyan>{$item['model']}</>");
        }

        // 2. Needs rename
        if (!empty($rename)) {
            $this->newLine();
            $this->warn("⚠️  NEEDS RENAME (" . count($rename) . "):");
            foreach ($rename as $item) {
                $this->line("  <fg=yellow>→</> Table: <fg=cyan>{$item['table']}</>");
                $this->line("     Current: <fg=red>{$item['current_model']}</> → Expected: <fg=green>{$item['expected_model']}</>");
            }
        }

        // 3. Missing
        if (!empty($missing)) {
            $this->newLine();
            $this->error("❌ MISSING (" . count($missing) . "):");
            foreach ($missing as $item) {
                $this->line("  <fg=red>✗</> Table: <fg=cyan>{$item['table']}</> → Create: <fg=yellow>{$item['expected_model']}</>");
            }
        }

        // 4. Extra models
        if (!empty($extra)) {
            $this->newLine();
            $this->error("🗑️  EXTRA MODELS TO DELETE (" . count($extra) . "):");
            foreach ($extra as $item) {
                $this->line("  <fg=red>✗</> {$item['model']} (\$table = {$item['table_prop']})");
            }
        }

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("  Tables in MacroData: <fg=cyan>" . count($tableNames) . '</>');
        $this->line("  Model files: <fg=cyan>" . count($existingModels) . '</>');
        $this->newLine();
        $this->line("  <fg=green>✓ Perfect: " . count($perfect) . '</>');
        $this->line("  <fg=yellow>→ Rename: " . count($rename) . '</>');
        $this->line("  <fg=red>✗ Missing: " . count($missing) . '</>');
        $this->line("  <fg=red>🗑️  Extra: " . count($extra) . '</>');

        // Generate commands for fixes
        if (!empty($rename)) {
            $this->newLine();
            $this->info('=== Commands to RENAME models ===');
            foreach ($rename as $item) {
                $old = $modelsPath . '/' . $item['current_model'] . '.php';
                $new = $modelsPath . '/' . $item['expected_model'] . '.php';
                $this->line("  mv {$item['current_model']}.php {$item['expected_model']}.php");
            }
        }

        return (empty($rename) && empty($missing) && empty($extra)) ? 0 : 1;
    }

    /**
     * Convert table name (snake_case) to model name (PascalCase).
     * e.g., estate_buys → EstateBuys, users → Users
     */
    protected function tableToModel(string $tableName): string
    {
        $parts = explode('_', $tableName);
        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * Convert model name (PascalCase) to table name (snake_case).
     * e.g., EstateBuys → estate_buys, Users → users
     */
    protected function modelToTable(string $modelName): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $modelName));
    }
}
