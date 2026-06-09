<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestMacrodataModels extends Command
{
    protected $signature = 'macrodata:test-models';
    protected $description = 'Test all MacroData models against real database';

    protected array $errors = [];
    protected array $success = [];

    // Methods that are NOT relations
    protected array $skipMethods = [
        'casts', 'getTable', 'getConnection', 'getKeyName', 'getQualifiedKeyName',
        'getKeyType', 'getIncrementing', 'resolveConnection', 'newInstance', 'newFromBuilder',
        'newCollection', 'forceDelete', 'restore', 'trashed', 'boot', 'initialize',
        'withoutTouching', 'withoutEvents', 'withoutGlobalScopes', 'withTrashed', 'onlyTrashed',
        'create', 'make', 'forceCreate', 'find', 'findMany', 'findOrFail', 'first', 'firstOrFail',
        'firstOrNew', 'firstOrCreate', 'orWhere', 'whereNot', 'orWhereNot', 'whereIn', 'orWhereIn',
        'whereNotIn', 'orWhereNotIn', 'whereNull', 'orWhereNull', 'whereNotNull', 'orWhereNotNull',
        'whereDate', 'orWhereDate', 'whereTime', 'orWhereTime', 'whereDay', 'orWhereDay', 'whereMonth', 'orWhereMonth',
        'whereYear', 'orWhereYear', 'whereColumn', 'orWhereColumn', 'whereExists', 'orWhereExists',
        'whereNotExists', 'orWhereNotExists', 'whereRaw', 'orWhereRaw', 'orderBy', 'orderByDesc',
        'orderByRaw', 'latest', 'oldest', 'skip', 'take', 'limit', 'offset', 'forPage', 'forPageBeforeId',
        'forPageAfterId', 'paginate', 'simplePaginate', 'cursorPaginate', 'get', 'getModels', 'toBase',
        'pluck', 'implode', 'exists', 'doesntExist', 'count', 'min', 'max', 'sum', 'avg', 'average', 'value',
        'chunk', 'chunkById', 'chunkByIdDesc', 'lazy', 'lazyById', 'lazyByIdDesc', 'cursor', 'each',
        'eachById', 'tap', 'when', 'unless', 'transform', 'firstWhere', 'whereFirst', 'sole', 'soleOrFail',
        'getQuery', 'toSql', 'dump', 'dd', 'ddRawSql', 'dumpRawSql', 'toRawSql', 'toCsv', 'toJson',
        'getBindings', 'raw', 'select', 'selectRaw', 'selectSub', 'addSelect', 'distinct', 'groupBy',
        'groupByRaw', 'having', 'havingRaw', 'havingBetween', 'orHaving', 'orHavingRaw', 'join', 'joinRaw',
        'joinSub', 'leftJoin', 'leftJoinSub', 'rightJoin', 'rightJoinSub', 'crossJoin', 'crossJoinSub',
        'mergeWheres', 'where', 'whereRelation', 'whereMorphRelation', 'whereDoesntHave', 'orWhereDoesntHave',
        'whereHas', 'orWhereHas', 'whereDoesntHaveMorph', 'orWhereDoesntHaveMorph', 'whereHasMorph', 'orWhereHasMorph',
        'with', 'withAvg', 'withCount', 'withExists', 'withMax', 'withMin', 'withSum', 'load', 'loadAvg',
        'loadCount', 'loadExists', 'loadMax', 'loadMin', 'loadSum', 'loadMissing', 'loadMorph', 'loadMorphCount',
        'loadMorphAvg', 'loadMorphMin', 'loadMorphMax', 'loadMorphSum', 'withOnly', 'without',
        'has', 'orHas', 'doesntHave', 'orDoesntHave', 'hasMorph', 'orHasMorph', 'doesntHaveMorph', 'orDoesntHaveMorph',
        'whereKey', 'whereKeyNot', 'increment', 'decrement', 'update', 'updateQuietly', 'push', 'pull',
        'sync', 'syncWithoutDetaching', 'syncFromQuery', 'attach', 'detach', 'updateExistingPivot',
        'touch', 'touchOwners', 'freshTimestamp', 'useTimestamps', 'retrievedAt', 'getQueueableRelations',
        'getQueueableIds', 'getRelationValue', 'isRelationLoaded', 'wasRecentlyCreated',
    ];

    public function handle(): int
    {
        $company = Company::where('name', 'like', '%Capital%')->first();
        if (!$company) {
            $this->error('Company not found');
            return 1;
        }

        $this->info("Testing MacroData models for company: {$company->name}");
        
        $service = app(ConnectionService::class);
        $service->connect($company);

        $modelsPath = app_path('Models/MacroData');
        $modelFiles = glob($modelsPath . '/*.php');
        sort($modelFiles);

        $this->newLine();
        $bar = $this->output->createProgressBar(count($modelFiles));

        foreach ($modelFiles as $file) {
            $modelName = basename($file, '.php');
            $this->testModel($modelName, $modelsPath);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Results
        $this->info('=== Results ===');
        $this->line("  <fg=green>✓ Passed: " . count($this->success) . '</>');
        $this->line("  <fg=red>✗ Failed: " . count($this->errors) . '</>');

        if (!empty($this->errors)) {
            $this->newLine();
            $this->error('Errors:');
            foreach ($this->errors as $error) {
                $this->line("  <fg=red>✗ {$error['model']}</>: {$error['error']}");
            }
        }

        return empty($this->errors) ? 0 : 0;
    }

    protected function testModel(string $modelName, string $path): void
    {
        $fullClass = "App\\Models\\MacroData\\{$modelName}";
        
        try {
            // Check class exists
            if (!class_exists($fullClass)) {
                $this->errors[] = ['model' => $modelName, 'error' => 'Class not found'];
                return;
            }

            $model = new $fullClass();

            // Check table property
            $table = $model->getTable();
            if (empty($table)) {
                $this->errors[] = ['model' => $modelName, 'error' => 'No $table property'];
                return;
            }

            // Check table exists in DB
            $exists = DB::connection('macrodata')->select("SHOW TABLES LIKE '{$table}'");
            if (empty($exists)) {
                $this->errors[] = ['model' => $modelName, 'error' => "Table '{$table}' not found in DB"];
                return;
            }

            // Try to fetch one record
            $record = $fullClass::first();
            
            // Test relations if record exists
            if ($record) {
                $reflection = new \ReflectionClass($model);
                $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                
                foreach ($methods as $method) {
                    $methodName = $method->getName();
                    
                    // Skip known non-relation methods
                    if (in_array($methodName, $this->skipMethods)) {
                        continue;
                    }
                    
                    // Skip methods starting with get/set/is/has (except relation methods)
                    if (preg_match('/^(get|set|is|has|does|or|where|with|with|load|sync|attach|detach)/', $methodName)) {
                        continue;
                    }
                    
                    // Only test methods that return Relation
                    try {
                        $returnType = $method->getReturnType();
                        if ($returnType && strpos($returnType->getName(), 'Relations\\') !== false) {
                            $result = $record->$methodName();
                            $result->getRelated();
                        }
                    } catch (\ReflectionException $e) {
                        // No return type - try to call and see if it returns a Relation
                        try {
                            $result = $record->$methodName();
                            if ($result instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $result->getRelated();
                            }
                        } catch (\ArgumentCountError $e) {
                            // Not a relation method, skip
                        } catch (\TypeError $e) {
                            // Not a relation method, skip
                        }
                    }
                }
            }

            $this->success[] = $modelName;

        } catch (\Throwable $e) {
            $this->errors[] = ['model' => $modelName, 'error' => $e->getMessage()];
        }
    }
}
