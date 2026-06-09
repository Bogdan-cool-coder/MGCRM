<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateRelationsDoc extends Command
{
    protected $signature = 'macrodata:generate-relations';
    protected $description = 'Parse relations from models and MACRODATA.md, generate complete RELATIONS.md';

    protected array $modelRelations = [];
    protected array $docRelations = [];
    protected array $allTables = [];

    public function handle(): int
    {
        $modelsPath = app_path('Models/MacroData');
        $macrodataFile = base_path('MACRODATA.md');
        $outputFile = base_path('RELATIONS.md');

        // Step 1: Parse relations from model files
        $this->info('Step 1: Parsing relations from models...');
        $this->parseModelRelations($modelsPath);
        $this->line("  Found " . count($this->modelRelations) . " tables with relations in models");

        // Step 2: Parse relations from MACRODATA.md
        $this->newLine();
        $this->info('Step 2: Parsing relations from MACRODATA.md...');
        $this->parseDocRelations($macrodataFile);
        $this->line("  Found " . count($this->docRelations) . " tables with relations in docs");

        // Step 3: Get all tables from models
        $modelFiles = glob($modelsPath . '/*.php');
        foreach ($modelFiles as $file) {
            $modelName = basename($file, '.php');
            $content = file_get_contents($file);
            if (preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"];/", $content, $matches)) {
                $this->allTables[$matches[1]] = $modelName;
            }
        }

        // Step 4: Generate complete RELATIONS.md
        $this->newLine();
        $this->info('Step 3: Generating RELATIONS.md...');
        $content = $this->generateMarkdown();
        file_put_contents($outputFile, $content);
        $this->line("  Written to: {$outputFile}");

        // Step 5: Show stats
        $this->newLine();
        $this->info('=== Stats ===');
        
        $totalModelRels = 0;
        foreach ($this->modelRelations as $table => $data) {
            $totalModelRels += count($data['relations']);
        }
        
        $totalDocRels = 0;
        foreach ($this->docRelations as $table => $links) {
            $totalDocRels += count($links);
        }
        
        $this->line("  Model relations: {$totalModelRels}");
        $this->line("  Doc relations: {$totalDocRels}");
        $this->line("  Tables: " . count($this->allTables));

        $this->newLine();
        $this->info('Done!');

        return 0;
    }

    protected function parseModelRelations(string $path): void
    {
        $modelFiles = glob($path . '/*.php');

        foreach ($modelFiles as $file) {
            $modelName = basename($file, '.php');
            $content = file_get_contents($file);

            // Get $table property
            if (!preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"];/", $content, $tableMatch)) {
                continue;
            }
            $tableName = $tableMatch[1];

            // Parse all relation methods - multiline support
            // Match: public function name() { return $this->belongsTo(Model::class, 'fk', 'pk'); }
            // Or multiline version
            $relations = [];

            // First, find all public function definitions
            preg_match_all(
                '/public\s+function\s+(\w+)\s*\(\)\s*(?::\s*\??\\\\?\w+)?\s*\{([^}]+return\s+\$this->(belongsTo|hasOne|hasMany|belongsToMany)[^;]+;[^}]*)\}/s',
                $content,
                $methodMatches,
                PREG_SET_ORDER
            );

            foreach ($methodMatches as $methodMatch) {
                $methodName = $methodMatch[1];
                $methodBody = $methodMatch[2];
                $relationType = $methodMatch[3];

                // Extract Model::class, foreignKey, ownerKey from method body
                // Handle both single-line and multi-line formats
                if (preg_match(
                    '/(\w+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]+)[\'"])?/s',
                    $methodBody,
                    $relMatch
                )) {
                    $relatedModel = $relMatch[1];
                    $foreignKey = $relMatch[2];
                    $ownerKey = $relMatch[3] ?? null;

                    $relatedTable = $this->findTableByModel($relatedModel, $path);

                    $relations[] = [
                        'method' => $methodName,
                        'type' => $relationType,
                        'related_model' => $relatedModel,
                        'related_table' => $relatedTable,
                        'foreign_key' => $foreignKey,
                        'owner_key' => $ownerKey,
                    ];
                }
            }

            if (!empty($relations)) {
                $this->modelRelations[$tableName] = [
                    'model' => $modelName,
                    'relations' => $relations,
                ];
            }
        }
    }

    protected function findTableByModel(string $modelName, string $path): ?string
    {
        $file = $path . '/' . $modelName . '.php';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"];/", $content, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    protected function parseDocRelations(string $file): void
    {
        $content = file_get_contents($file);

        // Split by "## table_name" sections
        $sections = preg_split('/^##\s+(\w+)/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        for ($i = 1; $i < count($sections); $i += 2) {
            $tableName = $sections[$i];
            $sectionContent = $sections[$i + 1] ?? '';

            // Look for "Связи ... с другими таблицами" section
            if (preg_match('/Связи\s+\w+\s+с\s+другими\s+таблицами\s*\n+\s*\| Field \| Links \|.*?\n\s*\|[-| ]+\|\s*\n(.*?)(?=\n\n|\n---|$)/s', $sectionContent, $match)) {
                $linksText = $match[1];
                $links = [];
                
                preg_match_all('/\|\s*([\w.]+)\s*\|\s*([\w.,]+)\s*\|/', $linksText, $linkMatches, PREG_SET_ORDER);
                
                foreach ($linkMatches as $linkMatch) {
                    $field = trim($linkMatch[1]);
                    $targets = trim($linkMatch[2]);
                    
                    // Skip header rows
                    if ($field === 'Field' || empty($field) || strpos($field, '---') !== false) {
                        continue;
                    }
                    
                    $links[$field] = $targets;
                }

                if (!empty($links)) {
                    $this->docRelations[$tableName] = $links;
                }
            }
        }
    }

    protected function generateMarkdown(): string
    {
        $md = "# MacroData Relations\n\n";
        $md .= "Автоматически сгенерированная документация связей между таблицами MacroData.\n\n";
        $md .= "**Формат:**\n";
        $md .= "- Таблица → поля с `_id` → связанная таблица\n";
        $md .= "- Метод = название relation в модели (camelCase)\n";
        $md .= "- Статус: ✅ реализовано / ⚠️ не реализовано\n\n";
        $md .= "---\n\n";

        // Sort tables
        $allTableNames = array_unique(array_merge(
            array_keys($this->modelRelations),
            array_keys($this->docRelations),
            array_keys($this->allTables)
        ));
        sort($allTableNames);

        foreach ($allTableNames as $tableName) {
            $modelName = $this->allTables[$tableName] ?? $this->tableToModel($tableName);
            $modelRels = $this->modelRelations[$tableName]['relations'] ?? [];
            $docRels = $this->docRelations[$tableName] ?? [];

            // Skip if no relations at all
            if (empty($modelRels) && empty($docRels)) {
                continue;
            }

            $md .= "## {$tableName}\n\n";
            $md .= "**Model:** `{$modelName}`\n\n";

            if (empty($modelRels) && empty($docRels)) {
                $md .= "*Нет исходящих связей*\n\n---\n\n";
                continue;
            }

            $md .= "| Поле | Связь | Метод | Тип | Статус |\n";
            $md .= "|------|-------|-------|-----|--------|\n";

            // Build lookup of model relations by foreign_key
            $modelRelsByField = [];
            foreach ($modelRels as $rel) {
                $modelRelsByField[$rel['foreign_key']] = $rel;
            }

            // Process all documented relations
            foreach ($docRels as $field => $targets) {
                // Remove table prefix if exists
                $baseField = preg_replace('/^\w+\./', '', $field);

                if (isset($modelRelsByField[$baseField])) {
                    $rel = $modelRelsByField[$baseField];
                    $target = $rel['related_table'] 
                        ? "{$rel['related_table']}." . ($rel['owner_key'] ?? 'id')
                        : $rel['related_model'];
                    $md .= "| `{$baseField}` | `{$target}` | `{$rel['method']}()` | {$rel['type']} | ✅ |\n";
                    unset($modelRelsByField[$baseField]);
                } else {
                    $method = $this->suggestMethodName($field);
                    $md .= "| `{$baseField}` | `{$targets}` | `{$method}()` | belongsTo | ⚠️ |\n";
                }
            }

            // Add remaining model relations not in docs
            foreach ($modelRelsByField as $field => $rel) {
                $target = $rel['related_table'] 
                    ? "{$rel['related_table']}." . ($rel['owner_key'] ?? 'id')
                    : $rel['related_model'];
                $md .= "| `{$field}` | `{$target}` | `{$rel['method']}()` | {$rel['type']} | ✅ |\n";
            }

            $md .= "\n---\n\n";
        }

        // Add summary
        $md .= "## Summary\n\n";
        
        $totalModels = count($this->modelRelations);
        $totalRels = 0;
        foreach ($this->modelRelations as $data) {
            $totalRels += count($data['relations']);
        }
        
        $md .= "- Tables with relations: **{$totalModels}**\n";
        $md .= "- Total relations: **{$totalRels}**\n";

        return $md;
    }

    protected function suggestMethodName(string $field): string
    {
        // Remove table prefix if exists
        $field = preg_replace('/^\w+\./', '', $field);
        
        // Remove _id suffix
        $base = preg_replace('/_id$/', '', $field);
        
        // Convert to camelCase
        $parts = explode('_', $base);
        $method = '';
        foreach ($parts as $i => $part) {
            if ($i === 0) {
                $method = $part;
            } else {
                $method .= ucfirst($part);
            }
        }

        return $method;
    }

    protected function tableToModel(string $tableName): string
    {
        $parts = explode('_', $tableName);
        return implode('', array_map('ucfirst', $parts));
    }
}
