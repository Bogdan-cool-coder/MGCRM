<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Tests\TestCase;

/**
 * Unit tests for the `options` column-config key in ReportDataService.
 *
 * Extends Tests\TestCase (boots a minimal Laravel app via sqlite::memory:) so
 * that app()->setLocale() / app()->getLocale() work correctly. No migrations
 * or seeders are required — DB calls go to SQLite stubs built in-test.
 *
 * Coverage:
 *
 * applyOptionsLabel() — still exists as a private utility but is no longer
 * used in the main data-path. Tests 1–8 verify it still works for edge cases.
 *
 *   1. Known value + ru locale   → ru label returned
 *   2. Known value + en locale   → en label returned
 *   3. Unknown value             → raw value (graceful fallback)
 *   4. No `options` in config    → raw value (back-compat)
 *   5. Flat string entry         → returned as-is regardless of locale
 *   6. Null value                → null returned unchanged
 *   7. Locale not in entry, en fallback present → en label
 *   8. Locale not in entry, no en, first key used
 *
 * mapRow() integration — raw value preserved:
 *   9. Row field stays RAW when column has `options` (no server-side substitution)
 *  10. Row field stays raw when column has no `options` (unchanged)
 *
 * resolveOptionsFilterLabel() — label is {ru,en} object:
 *  11. Known value → returns {ru,en} object (not a locale string)
 *  12. Unknown value → returns raw string
 *  13. No options map → returns raw string
 *  14. Flat scalar entry → returns flat scalar (back-compat)
 *
 * getSelectOptions() integration (via SQLite stub):
 *  15. select filter options → value=raw, label={ru,en} object for mapped values
 *  16. select filter options → label=raw when column has no options
 *
 * columns metadata — options map flows through getVisibleColumns():
 *  17. getVisibleColumns() preserves `options` key in column metadata
 */
class OptionsLabelTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(array $config = []): ReportDataService
    {
        $ref     = new ReflectionClass(ReportDataService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($service, $config);

        try {
            $elProp = $ref->getProperty('expressionLanguage');
            $elProp->setAccessible(true);
            $elProp->setValue($service, new \Symfony\Component\ExpressionLanguage\ExpressionLanguage());
        } catch (\Throwable) {}

        return $service;
    }

    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    private function makeModelStub(array $attributes): Model
    {
        return new class ($attributes) extends Model {
            private array $attrs;

            public function __construct(array $attrs)
            {
                $this->attrs = $attrs;
            }

            public function __get($key): mixed
            {
                return $this->attrs[$key] ?? null;
            }

            public function getTable(): string { return 'estate_sells'; }
            public function getKey(): mixed    { return 1; }
        };
    }

    /** Minimal column config with localised options map */
    private function columnWithOptions(): array
    {
        return [
            'field' => 'estate_sell_category',
            'type'  => 'text',
            'options' => [
                'flat'    => ['ru' => 'Квартира',  'en' => 'Flat'],
                'garage'  => ['ru' => 'Парковка',  'en' => 'Garage'],
                'comm'    => ['ru' => 'Коммерция', 'en' => 'Commercial'],
                'storage' => ['ru' => 'Кладовая',  'en' => 'Storage'],
            ],
        ];
    }

    // =========================================================================
    // applyOptionsLabel() — unit tests (no DB)
    // =========================================================================

    /** Test 1: known value + ru locale → ru label */
    public function test_known_value_ru_locale_returns_ru_label(): void
    {
        app()->setLocale('ru');
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['flat', $this->columnWithOptions()]);
        $this->assertSame('Квартира', $result);
    }

    /** Test 2: known value + en locale → en label */
    public function test_known_value_en_locale_returns_en_label(): void
    {
        app()->setLocale('en');
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['comm', $this->columnWithOptions()]);
        $this->assertSame('Commercial', $result);
    }

    /** Test 3: unknown value → raw value returned */
    public function test_unknown_value_returns_raw(): void
    {
        app()->setLocale('ru');
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['townhouse', $this->columnWithOptions()]);
        $this->assertSame('townhouse', $result);
    }

    /** Test 4: column with no `options` key → raw value (back-compat) */
    public function test_no_options_key_returns_raw(): void
    {
        app()->setLocale('ru');
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['flat', ['field' => 'estate_sell_category', 'type' => 'text']]);
        $this->assertSame('flat', $result);
    }

    /** Test 5: flat string entry → returned as-is regardless of locale */
    public function test_flat_string_entry_returned_as_is(): void
    {
        app()->setLocale('ru');
        $service = $this->makeService();
        $column  = ['field' => 'f', 'options' => ['flat' => 'Квартира']];
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['flat', $column]);
        $this->assertSame('Квартира', $result);
    }

    /** Test 6: null value → null returned unchanged */
    public function test_null_value_returns_null(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', [null, $this->columnWithOptions()]);
        $this->assertNull($result);
    }

    /** Test 7: locale not in entry, en fallback present */
    public function test_locale_fallback_to_en(): void
    {
        app()->setLocale('de');
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['flat', $this->columnWithOptions()]);
        $this->assertSame('Flat', $result);
    }

    /** Test 8: locale not in entry, no en key → first key used */
    public function test_locale_fallback_to_first_key(): void
    {
        app()->setLocale('de');
        $service = $this->makeService();
        $column  = ['field' => 'f', 'options' => ['flat' => ['fr' => 'Appartement', 'es' => 'Piso']]];
        $result  = $this->callProtected($service, 'applyOptionsLabel', ['flat', $column]);
        $this->assertSame('Appartement', $result);
    }

    // =========================================================================
    // mapRow() — raw value must be preserved (no server-side substitution)
    // =========================================================================

    private function makeServiceForMapRow(array $columns): ReportDataService
    {
        $config  = ['columns' => $columns, 'group_by' => null];
        $service = $this->makeService($config);

        $ref   = new ReflectionClass($service);
        $mProp = $ref->getProperty('modelInstance');
        $mProp->setAccessible(true);
        $mProp->setValue($service, $this->makeModelStub([]));

        return $service;
    }

    /** Test 9: mapRow keeps raw value even when column has options */
    public function test_map_row_keeps_raw_value_when_column_has_options(): void
    {
        app()->setLocale('ru');

        $columns = [$this->columnWithOptions()];
        $service = $this->makeServiceForMapRow($columns);
        $item    = $this->makeModelStub(['estate_sell_category' => 'garage']);

        $row = $this->callProtected($service, 'mapRow', [$item, 0, 1, 20, []]);
        // Raw 'garage' must come through — frontend localises via options metadata.
        $this->assertSame('garage', $row['estate_sell_category']);
    }

    /** Test 10: mapRow keeps raw value when column has no options (unchanged behaviour) */
    public function test_map_row_keeps_raw_without_options(): void
    {
        app()->setLocale('ru');

        $columns = [['field' => 'estate_sell_category', 'type' => 'text']];
        $service = $this->makeServiceForMapRow($columns);
        $item    = $this->makeModelStub(['estate_sell_category' => 'flat']);

        $row = $this->callProtected($service, 'mapRow', [$item, 0, 1, 20, []]);
        $this->assertSame('flat', $row['estate_sell_category']);
    }

    // =========================================================================
    // resolveOptionsFilterLabel() — label must be {ru,en} object, not a string
    // =========================================================================

    /** Test 11: known value → returns {ru,en} object */
    public function test_resolve_options_filter_label_returns_object_for_known_value(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveOptionsFilterLabel', ['flat', $this->columnWithOptions()]);
        $this->assertIsArray($result);
        $this->assertSame('Квартира', $result['ru']);
        $this->assertSame('Flat',     $result['en']);
    }

    /** Test 12: unknown value → returns raw string */
    public function test_resolve_options_filter_label_returns_raw_for_unknown_value(): void
    {
        $service = $this->makeService();
        $result  = $this->callProtected($service, 'resolveOptionsFilterLabel', ['townhouse', $this->columnWithOptions()]);
        $this->assertSame('townhouse', $result);
    }

    /** Test 13: no options map → returns raw string */
    public function test_resolve_options_filter_label_returns_raw_without_map(): void
    {
        $service = $this->makeService();
        $column  = ['field' => 'estate_sell_category', 'type' => 'text'];
        $result  = $this->callProtected($service, 'resolveOptionsFilterLabel', ['flat', $column]);
        $this->assertSame('flat', $result);
    }

    /** Test 14: flat scalar entry → returns flat scalar (back-compat) */
    public function test_resolve_options_filter_label_returns_flat_scalar(): void
    {
        $service = $this->makeService();
        $column  = ['field' => 'f', 'options' => ['flat' => 'Квартира']];
        $result  = $this->callProtected($service, 'resolveOptionsFilterLabel', ['flat', $column]);
        $this->assertSame('Квартира', $result);
    }

    // =========================================================================
    // getSelectOptions() integration (via SQLite stub)
    // =========================================================================

    /**
     * Build a SQLite-backed Builder pre-populated with estate_sell_category values.
     * Returns [$builder, $service].
     */
    private function makeSelectFilterSetup(array $rows, array $columnConfig): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE estate_sells (id INTEGER PRIMARY KEY, estate_sell_category TEXT)');
        $stmt = $pdo->prepare('INSERT INTO estate_sells (estate_sell_category) VALUES (?)');
        foreach ($rows as $v) {
            $stmt->execute([$v]);
        }

        $conn = new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '');

        $model = new class extends Model {
            protected $table = 'estate_sells';
            public $timestamps = false;
            public function __construct(array $a = []) {}
            public function getTable(): string { return 'estate_sells'; }
        };

        $qb      = $conn->query()->from('estate_sells');
        $builder = new Builder($qb);
        $builder->setModel($model);

        $service = $this->makeService(['columns' => [$columnConfig]]);

        return [$builder, $service];
    }

    /** Test 15: select filter options → value=raw, label={ru,en} object for mapped values */
    public function test_select_options_label_is_object_for_mapped_values(): void
    {
        [$builder, $service] = $this->makeSelectFilterSetup(
            ['flat', 'comm', 'garage'],
            $this->columnWithOptions()
        );

        $options = $this->callProtected($service, 'getSelectOptions', [$builder, 'estate_sell_category', 100, $this->columnWithOptions()]);

        $this->assertNotNull($options);

        $byValue = [];
        foreach ($options as $opt) {
            $byValue[$opt['value']] = $opt['label'];
        }

        // value stays raw
        $this->assertArrayHasKey('flat',   $byValue);
        $this->assertArrayHasKey('comm',   $byValue);
        $this->assertArrayHasKey('garage', $byValue);

        // label is a {ru,en} array, not a locale string
        $this->assertIsArray($byValue['flat']);
        $this->assertSame('Квартира', $byValue['flat']['ru']);
        $this->assertSame('Flat',     $byValue['flat']['en']);

        $this->assertIsArray($byValue['comm']);
        $this->assertSame('Коммерция', $byValue['comm']['ru']);
        $this->assertSame('Commercial', $byValue['comm']['en']);
    }

    /** Test 16: select filter options → label=raw when column has no options */
    public function test_select_options_label_equals_raw_without_options(): void
    {
        $columnConfig = ['field' => 'estate_sell_category', 'type' => 'text'];
        [$builder, $service] = $this->makeSelectFilterSetup(['flat', 'comm'], $columnConfig);

        $options = $this->callProtected($service, 'getSelectOptions', [$builder, 'estate_sell_category', 100, $columnConfig]);

        $this->assertNotNull($options);
        foreach ($options as $opt) {
            $this->assertSame($opt['value'], $opt['label']);
        }
    }

    // =========================================================================
    // getVisibleColumns() — options map must flow through to columns metadata
    // =========================================================================

    /** Test 17: getVisibleColumns() preserves `options` key in column metadata */
    public function test_get_visible_columns_preserves_options_key(): void
    {
        $columns = [$this->columnWithOptions()];
        $service = $this->makeService(['columns' => $columns]);

        $visible = $this->callProtected($service, 'getVisibleColumns', []);

        $this->assertCount(1, $visible);
        $this->assertArrayHasKey('options', $visible[0]);
        $this->assertSame($this->columnWithOptions()['options'], $visible[0]['options']);
    }
}
