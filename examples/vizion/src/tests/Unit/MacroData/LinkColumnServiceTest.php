<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for link-type column mechanics in ReportDataService.
 *
 * Covers both the legacy label_field and the new label_lines mechanisms.
 * No database connection required — protected methods are called via reflection,
 * and Eloquent models are stubbed with anonymous sub-classes.
 */
class LinkColumnServiceTest extends TestCase
{
    private function makeService(array $config = []): ReportDataService
    {
        $ref     = new ReflectionClass(ReportDataService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Inject config via reflection so protected helpers can read it
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($service, $config);

        return $service;
    }

    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    /**
     * Build a minimal Eloquent stub that returns attribute values from an array.
     */
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
        };
    }

    // =========================================================================
    // extraFieldsForColumns
    // =========================================================================

    public function test_extraFieldsForColumns_returns_empty_for_non_link(): void
    {
        $service = $this->makeService();
        $columns = [
            ['field' => 'deal_sum', 'type' => 'currency'],
            ['field' => 'status',   'type' => 'badge'],
        ];

        $result = $this->callProtected($service, 'extraFieldsForColumns', [$columns]);

        $this->assertSame([], $result);
    }

    public function test_extraFieldsForColumns_collects_label_field(): void
    {
        $service = $this->makeService();
        $columns = [
            ['field' => 'deal_id', 'type' => 'link', 'label_field' => 'deal_number'],
        ];

        $result = $this->callProtected($service, 'extraFieldsForColumns', [$columns]);

        $this->assertContains('deal_number', $result);
    }

    public function test_extraFieldsForColumns_collects_label_lines_fields(): void
    {
        $service = $this->makeService();
        $columns = [
            [
                'field' => 'deal_id',
                'type'  => 'link',
                'label_lines' => [
                    ['prefix' => ['ru' => 'Основной'],        'field' => 'agreement_number',    'default' => ['ru' => 'Не указан']],
                    ['prefix' => ['ru' => 'Предварительный'], 'field' => 'preliminary_number',  'default' => ['ru' => 'Не указан']],
                    ['prefix' => ['ru' => 'Договор-основание'],'field' => 'arles_agreement_num','default' => ['ru' => 'Не указан']],
                ],
            ],
        ];

        $result = $this->callProtected($service, 'extraFieldsForColumns', [$columns]);

        $this->assertContains('agreement_number',    $result);
        $this->assertContains('preliminary_number',  $result);
        $this->assertContains('arles_agreement_num', $result);
        $this->assertCount(3, $result);
    }

    public function test_extraFieldsForColumns_deduplicates_shared_fields(): void
    {
        $service = $this->makeService();
        // Two link columns both referencing the same auxiliary field via label_lines
        $columns = [
            [
                'field' => 'deal_id',
                'type'  => 'link',
                'label_lines' => [
                    ['field' => 'agreement_number'],
                ],
            ],
            [
                'field' => 'other_id',
                'type'  => 'link',
                'label_field' => 'agreement_number',
            ],
        ];

        $result = $this->callProtected($service, 'extraFieldsForColumns', [$columns]);

        // Must appear exactly once
        $this->assertCount(1, array_filter($result, fn($v) => $v === 'agreement_number'));
    }

    public function test_extraFieldsForColumns_handles_label_lines_with_missing_field_key(): void
    {
        // A malformed line without 'field' must not add an empty string to $extra
        $service = $this->makeService();
        $columns = [
            [
                'field' => 'deal_id',
                'type'  => 'link',
                'label_lines' => [
                    ['prefix' => ['ru' => 'Основной']],  // no 'field' key
                ],
            ],
        ];

        $result = $this->callProtected($service, 'extraFieldsForColumns', [$columns]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // mapRow — label_lines fields land in the row
    // =========================================================================

    public function test_mapRow_injects_label_lines_fields_into_row(): void
    {
        $config = [
            'columns' => [
                [
                    'field' => 'deal_id',
                    'type'  => 'link',
                    'label_lines' => [
                        ['prefix' => ['ru' => 'Основной'],        'field' => 'agreement_number',    'default' => ['ru' => 'Не указан']],
                        ['prefix' => ['ru' => 'Предварительный'], 'field' => 'preliminary_number',  'default' => ['ru' => 'Не указан']],
                        ['prefix' => ['ru' => 'Договор-основание'],'field' => 'arles_agreement_num','default' => ['ru' => 'Не указан']],
                    ],
                ],
            ],
        ];

        $service = $this->makeService($config);
        $model   = $this->makeModelStub([
            'deal_id'            => 42,
            'agreement_number'   => 'AGR-001',
            'preliminary_number' => null,
            'arles_agreement_num'=> 'BASE-999',
        ]);

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        $this->assertArrayHasKey('deal_id',             $row);
        $this->assertArrayHasKey('agreement_number',    $row);
        $this->assertArrayHasKey('preliminary_number',  $row);
        $this->assertArrayHasKey('arles_agreement_num', $row);

        $this->assertSame(42,         $row['deal_id']);
        $this->assertSame('AGR-001',  $row['agreement_number']);
        $this->assertNull($row['preliminary_number']);
        $this->assertSame('BASE-999', $row['arles_agreement_num']);
    }

    public function test_mapRow_legacy_label_field_still_works(): void
    {
        $config = [
            'columns' => [
                [
                    'field'       => 'deal_id',
                    'type'        => 'link',
                    'label_field' => 'deal_number',
                ],
            ],
        ];

        $service = $this->makeService($config);
        $model   = $this->makeModelStub([
            'deal_id'     => 7,
            'deal_number' => 'DN-007',
        ]);

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        $this->assertArrayHasKey('deal_id',     $row);
        $this->assertArrayHasKey('deal_number', $row);
        $this->assertSame('DN-007', $row['deal_number']);
    }

    /**
     * Regression: two link columns share the same `field` (e.g. estateSells.estate_sell_id)
     * but declare different label_field values.  The second column's label_field must still
     * land in the row even though the primary field was already written by the first column.
     *
     * Before the fix, the guard `if (array_key_exists($field, $row)) { continue; }` caused
     * the entire second iteration (including its label_field injection) to be skipped.
     */
    public function test_mapRow_two_link_columns_same_field_different_label_fields(): void
    {
        $config = [
            'columns' => [
                // First link column: field=sell_id, label_field=agreement_number
                [
                    'field'       => 'sell_id',
                    'type'        => 'link',
                    'label_field' => 'agreement_number',
                ],
                // Second link column: same field=sell_id, different label_field=geo_flatnum
                [
                    'field'       => 'sell_id',
                    'type'        => 'link',
                    'label_field' => 'geo_flatnum',
                ],
            ],
        ];

        $service = $this->makeService($config);
        $model   = $this->makeModelStub([
            'sell_id'          => 999,
            'agreement_number' => 'AGR-123',
            'geo_flatnum'      => '5-14-10-7',
        ]);

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        $this->assertArrayHasKey('sell_id',          $row, 'Primary field must be in row');
        $this->assertArrayHasKey('agreement_number', $row, 'First label_field must be in row');
        $this->assertArrayHasKey('geo_flatnum',      $row, 'Second label_field must be in row (regression)');

        $this->assertSame(999,         $row['sell_id']);
        $this->assertSame('AGR-123',   $row['agreement_number']);
        $this->assertSame('5-14-10-7', $row['geo_flatnum']);
    }

    public function test_mapRow_label_lines_does_not_overwrite_existing_column_value(): void
    {
        // If a label_lines field coincidentally matches a visible column field,
        // the column value (written in the main loop iteration) must not be overwritten.
        $config = [
            'columns' => [
                // This column happens to share the same field name as a label_lines entry
                ['field' => 'agreement_number', 'type' => 'text'],
                [
                    'field' => 'deal_id',
                    'type'  => 'link',
                    'label_lines' => [
                        ['field' => 'agreement_number', 'prefix' => ['ru' => 'Осн.']],
                    ],
                ],
            ],
        ];

        $service = $this->makeService($config);
        $model   = $this->makeModelStub([
            'deal_id'          => 1,
            'agreement_number' => 'AGR-ORIGINAL',
        ]);

        $row = $this->callProtected($service, 'mapRow', [$model, 0, 1, 20]);

        // Value must be 'AGR-ORIGINAL' — not overwritten by the label_lines pass
        $this->assertSame('AGR-ORIGINAL', $row['agreement_number']);
    }
}
