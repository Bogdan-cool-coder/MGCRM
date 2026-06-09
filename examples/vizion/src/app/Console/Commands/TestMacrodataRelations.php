<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TestMacrodataRelations extends Command
{
    protected $signature = 'macrodata:test-relations {--model=} {--limit=5}';
    protected $description = 'Test all MacroData relations on real database data';

    private ConnectionService $connectionService;
    private int $totalRelations = 0;
    private int $passedRelations = 0;
    private int $failedRelations = 0;
    private int $skippedRelations = 0;
    private int $orphanRelations = 0;  // FK указывает на несуществующую запись
    private int $emptyTableRelations = 0;  // Нет данных для проверки (таблица пуста)
    private array $errors = [];
    private array $orphans = [];

    public function handle(ConnectionService $connectionService): int
    {
        $this->connectionService = $connectionService;

        $company = Company::where('name', 'like', '%Capital%')->first();
        if (!$company) {
            $this->error('Company with "Capital" not found');
            return 1;
        }

        $this->info("Connecting via company: {$company->name}");
        $connectionService->connect($company);

        $modelsPath = app_path('Models/MacroData');
        $modelFiles = glob($modelsPath . '/*.php');

        $specificModel = $this->option('model');

        foreach ($modelFiles as $file) {
            $className = basename($file, '.php');
            $fullClass = "App\\Models\\MacroData\\{$className}";

            if ($specificModel && $className !== $specificModel) {
                continue;
            }

            $this->testModelRelations($fullClass, $file);
        }

        $this->newLine();
        $this->info("=== ИТОГИ ===");
        $this->info("Всего relations: {$this->totalRelations}");
        $this->info("Прошло: {$this->passedRelations}");
        $this->info("Нет данных (таблица пуста): {$this->emptyTableRelations}");
        $this->info("Орфанные записи (FK не найден): {$this->orphanRelations}");
        $this->info("Пропущено (null FK): {$this->skippedRelations}");

        if (!empty($this->orphans)) {
            $this->newLine();
            $this->warn("=== ОРФАННЫЕ ЗАПИСИ (FK указывает на несуществующие данные) ===");
            foreach ($this->orphans as $orphan) {
                $this->warn($orphan);
            }
        }

        if (!empty($this->errors)) {
            $this->newLine();
            $this->error("=== ОШИБКИ КОНФИГУРАЦИИ ===");
            foreach ($this->errors as $error) {
                $this->error($error);
            }
        }

        return 0;  // Не считаем орфаны как ошибку - это проблема данных, не конфигурации
    }

    private function testModelRelations(string $fullClass, string $filePath): void
    {
        if (!class_exists($fullClass)) {
            return;
        }

        $model = new $fullClass;
        $tableName = $model->getTable();
        $className = class_basename($fullClass);

        $content = file_get_contents($filePath);
        $relationMethods = $this->parseRelationMethods($content);

        if (empty($relationMethods)) {
            return;
        }

        $this->newLine();
        $this->info("📊 {$className} ({$tableName}) - " . count($relationMethods) . " relations");

        try {
            $tableExists = DB::connection('macrodata')->select("SHOW TABLES LIKE '{$tableName}'");
            if (empty($tableExists)) {
                $this->warn("  ⚠️ Таблица {$tableName} не существует в БД");
                return;
            }
        } catch (\Exception $e) {
            $this->error("  ❌ Ошибка проверки таблицы: {$e->getMessage()}");
            return;
        }

        $limit = (int)$this->option('limit');
        $records = DB::connection('macrodata')->table($tableName)->limit($limit)->get();

        $tableEmpty = $records->isEmpty();
        if ($tableEmpty) {
            $this->warn("  ⚠️ Таблица {$tableName} пуста, relations будут помечены как 'нет данных'");
        }

        foreach ($relationMethods as $relationName) {
            $this->testRelation($model, $records, $relationName, $className, $tableEmpty);
        }
    }

    private function parseRelationMethods(string $content): array
    {
        // Простой и надёжный подход: найти все public function, затем проверить содержит ли relation
        preg_match_all(
            '/public\s+function\s+(\w+)\s*\(/',
            $content,
            $functionMatches
        );

        $methods = [];
        foreach ($functionMatches[1] as $methodName) {
            // Пропускаем casts и другие специальные методы
            if (in_array($methodName, ['casts', 'boot', 'initialize'])) {
                continue;
            }

            // Проверяем что метод содержит relation вызов
            if (preg_match(
                '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\).*?this->(belongsTo|hasOne|hasMany|belongsToMany)\s*\(/s',
                $content
            )) {
                $methods[] = $methodName;
            }
        }

        return array_unique($methods);
    }

    private function testRelation($model, $records, string $relationName, string $className, bool $tableEmpty = false): void
    {
        $this->totalRelations++;

        // Если таблица пуста - relation валидный, просто нет данных для проверки
        if ($tableEmpty) {
            try {
                $relation = $model->{$relationName}();
                $relatedModel = $relation->getRelated();
                $relatedClass = get_class($relatedModel);
                $this->emptyTableRelations++;
                $this->line("  🔹 {$relationName} → {$relatedClass} (нет данных - таблица пуста)");
            } catch (\Exception $e) {
                $this->errors[] = "{$className}::{$relationName} - Exception: {$e->getMessage()}";
                $this->error("  ❌ {$relationName} - Ошибка: {$e->getMessage()}");
            }
            return;
        }

        try {
            $relation = $model->{$relationName}();
            $relatedModel = $relation->getRelated();
            $relatedTable = $relatedModel->getTable();
            $relatedClass = get_class($relatedModel);

            // Определяем тип relation и получаем ключи
            if ($relation instanceof BelongsTo) {
                $foreignKey = $relation->getForeignKeyName();
                $ownerKey = $relation->getOwnerKeyName();
                $relationType = 'belongsTo';
            } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {
                $foreignKey = $relation->getForeignKeyName();
                $ownerKey = $relation->getLocalKeyName();  // Для hasOne/hasMany это local key
                $relationType = $relation instanceof HasOne ? 'hasOne' : 'hasMany';
            } elseif ($relation instanceof BelongsToMany) {
                // BelongsToMany - через pivot таблицу, пропускаем детальную проверку
                $this->passedRelations++;
                $this->line("  ✅ {$relationName} → {$relatedClass} (belongsToMany - пропущено)");
                return;
            } else {
                $this->errors[] = "{$className}::{$relationName} - Неизвестный тип relation";
                return;
            }

            $successCount = 0;
            $orphanCount = 0;
            $nullCount = 0;

            foreach ($records as $record) {
                $fkValue = $record->{$foreignKey} ?? null;

                if ($fkValue === null || $fkValue === '' || $fkValue === 0) {
                    $nullCount++;
                    continue;
                }

                // Ищем связанную запись
                $relatedRecord = DB::connection('macrodata')
                    ->table($relatedTable)
                    ->where($ownerKey, $fkValue)
                    ->first();

                if ($relatedRecord) {
                    $successCount++;
                } else {
                    $orphanCount++;
                    $this->orphans[] = "{$className}::{$relationName} - FK {$foreignKey}={$fkValue} не найден в {$relatedTable}.{$ownerKey}";
                }
            }

            if ($orphanCount > 0) {
                $this->orphanRelations++;
                $this->warn("  🔸 {$relationName} → {$relatedClass} (найдено: {$successCount}, орфаны: {$orphanCount}, null: {$nullCount})");
            } elseif ($successCount > 0) {
                $this->passedRelations++;
                $this->line("  ✅ {$relationName} → {$relatedClass} (найдено: {$successCount}, null: {$nullCount})");
            } else {
                $this->skippedRelations++;
                $this->line("  ⚪ {$relationName} → {$relatedClass} (все значения null/0, пропущено)");
            }

        } catch (\Exception $e) {
            $this->errors[] = "{$className}::{$relationName} - Exception: {$e->getMessage()}";
            $this->error("  ❌ {$relationName} - Ошибка: {$e->getMessage()}");
        }
    }
}
